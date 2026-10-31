import { Editor } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import Placeholder from '@tiptap/extension-placeholder'
import Collaboration from '@tiptap/extension-collaboration'
import CollaborationCaret from '@tiptap/extension-collaboration-caret'
import * as Y from 'yjs'
import {
    Awareness,
    applyAwarenessUpdate,
    encodeAwarenessUpdate,
    removeAwarenessStates,
} from 'y-protocols/awareness'

// Typing is sent in small batches, cursors at most this often. Hibira
// allows 10 client events a second per connection.
const FLUSH_MS = 80
const AWARENESS_MS = 150
const REMOTE = 'hibira-remote'

export default function hibiraCollabEditor({
    state,
    collab,
    placeholder,
    disabled,
    labels,
}) {
    // The editor, the Y.Doc and the channel live outside Alpine's reactive
    // proxy: ProseMirror and Yjs compare objects by identity.
    let editor = null
    let session = null

    return {
        state,
        status: collab ? 'connecting' : 'local',
        members: [],
        active: {},
        canUndo: false,
        canRedo: false,
        linkOpen: false,
        linkHref: '',

        async init() {
            if (!collab || !window.Echo) {
                this.status = 'local'
                editor = this.makeEditor([], this.state)

                return
            }

            session = new Session(collab, this)

            try {
                await session.open()
            } catch (error) {
                if (!error.readOnly) {
                    console.error('[hibira-collab]', error)
                }

                session.close()
                session = null
                this.status = error.readOnly ? 'local' : 'offline'
                editor = this.makeEditor([], this.state)

                return
            }

            editor = this.makeEditor(
                [
                    Collaboration.configure({ document: session.doc }),
                    CollaborationCaret.configure({
                        provider: { awareness: session.awareness },
                        user: collab.user,
                    }),
                ],
                null,
            )
            session.join()
        },

        destroy() {
            session?.close()
            editor?.destroy()
            session = null
            editor = null
        },

        makeEditor(collaboration, content) {
            return new Editor({
                element: this.$refs.editor,
                editable: !disabled,
                content: content ?? undefined,
                extensions: [
                    StarterKit.configure({
                        undoRedo: collaboration.length ? false : undefined,
                        link: { openOnClick: false },
                    }),
                    Placeholder.configure({ placeholder: placeholder ?? '' }),
                    ...collaboration,
                ],
                editorProps: {
                    attributes: { class: 'hibira-collab-prose' },
                },
                onCreate: ({ editor }) => {
                    this.refresh(editor)

                    // A shared document can hold changes nobody has saved yet:
                    // the form saves what the editor shows.
                    if (collaboration.length) {
                        this.sync(editor)
                    }
                },
                onTransaction: ({ editor }) => this.refresh(editor),
                onUpdate: ({ editor }) => this.sync(editor),
            })
        },

        sync(editor) {
            const html = editor.isEmpty ? '' : editor.getHTML()

            if (html !== (this.state ?? '')) {
                this.state = html
            }
        },

        refresh(editor) {
            this.active = {
                bold: editor.isActive('bold'),
                italic: editor.isActive('italic'),
                strike: editor.isActive('strike'),
                code: editor.isActive('code'),
                h2: editor.isActive('heading', { level: 2 }),
                h3: editor.isActive('heading', { level: 3 }),
                bulletList: editor.isActive('bulletList'),
                orderedList: editor.isActive('orderedList'),
                blockquote: editor.isActive('blockquote'),
                codeBlock: editor.isActive('codeBlock'),
                link: editor.isActive('link'),
            }
            this.canUndo = editor.can().undo()
            this.canRedo = editor.can().redo()
        },

        run(command) {
            if (!editor) {
                return
            }

            const chain = editor.chain().focus()

            switch (command) {
                case 'h2':
                    chain.toggleHeading({ level: 2 }).run()
                    break
                case 'h3':
                    chain.toggleHeading({ level: 3 }).run()
                    break
                case 'link':
                    this.linkHref = editor.getAttributes('link').href ?? ''
                    this.linkOpen = true
                    this.$nextTick(() => this.$refs.link?.focus())
                    break
                default:
                    chain[command]().run()
            }
        },

        applyLink() {
            const href = this.linkHref.trim()
            const chain = editor?.chain().focus().extendMarkRange('link')

            if (chain) {
                href === '' ? chain.unsetLink().run() : chain.setLink({ href }).run()
            }

            this.linkOpen = false
        },

        removeLink() {
            this.linkHref = ''
            this.applyLink()
        },

        closeLink() {
            this.linkOpen = false
            editor?.commands.focus()
        },

        statusLabel() {
            return labels[this.status] ?? ''
        },
    }
}

/**
 * One editor's link to a shared document: loads it from the server, sends
 * local changes there (the server stores them and passes them on over
 * Hibira), and swaps cursors with the others on the presence channel.
 */
class Session {
    constructor(config, view) {
        this.config = config
        this.view = view
        this.doc = new Y.Doc()
        this.awareness = new Awareness(this.doc)
        this.generation = 0
        this.cursor = 0
        this.pending = []
        this.flushTimer = null
        this.sending = false
        this.closed = false
        this.clientsByMember = new Map()
        this.awarenessTimer = null
        this.awarenessDirty = false

        this.onDocUpdate = (update, origin) => {
            if (origin === REMOTE || this.closed) {
                return
            }

            this.pending.push(update)
            this.scheduleFlush()
        }

        this.onAwarenessUpdate = ({ added, updated, removed }, origin) => {
            if (origin !== 'local') {
                return
            }

            const changed = [...added, ...updated, ...removed]

            if (changed.includes(this.doc.clientID)) {
                this.awarenessDirty = true
                this.scheduleAwareness()
            }
        }
    }

    async open() {
        const body = await this.request('GET', this.config.urls.show, {
            source: this.config.source,
        })

        this.load(body)

        if (!body.seeded) {
            // Only an editor may start a document; viewers see the saved value.
            if (this.config.readOnly) {
                throw Object.assign(new Error('Not started yet'), { readOnly: true })
            }

            await this.seed()
        }

        this.doc.on('update', this.onDocUpdate)
        this.awareness.on('update', this.onAwarenessUpdate)
    }

    // The first editor to open a document fills it from the saved value.
    async seed() {
        const draft = new Y.Doc()
        const scratch = new Editor({
            element: document.createElement('div'),
            extensions: [
                StarterKit.configure({ undoRedo: false }),
                Collaboration.configure({ document: draft }),
            ],
        })

        if (this.view.state) {
            scratch.commands.setContent(this.view.state)
        }

        const update = Y.encodeStateAsUpdate(draft)
        scratch.destroy()
        draft.destroy()

        try {
            const body = await this.request('POST', this.config.urls.seed, {
                generation: this.generation,
                update: toBase64(update),
            })

            Y.applyUpdate(this.doc, update, REMOTE)
            this.cursor = Math.max(this.cursor, body.id)
        } catch (error) {
            // Someone else seeded it first: use theirs.
            if (error.status !== 409) {
                throw error
            }

            this.load(await this.request('GET', this.config.urls.show))
        }
    }

    load(body) {
        this.generation = body.generation

        for (const row of body.updates) {
            Y.applyUpdate(this.doc, fromBase64(row.u), REMOTE)
            this.cursor = Math.max(this.cursor, row.id)
        }
    }

    join() {
        const channel = window.Echo.join(this.config.channel)
        this.channel = channel

        channel
            .here((members) => {
                this.view.members = members
                this.view.status = 'live'
                this.awarenessDirty = true
                this.scheduleAwareness()
            })
            .joining((member) => {
                this.view.members = [...this.view.members.filter((m) => m.id !== member.id), member]
                // Let the newcomer see this cursor straight away.
                this.awarenessDirty = true
                this.scheduleAwareness()
            })
            .leaving((member) => {
                this.view.members = this.view.members.filter((m) => m.id !== member.id)
                const clients = this.clientsByMember.get(String(member.id))

                if (clients) {
                    removeAwarenessStates(this.awareness, [...clients], REMOTE)
                    this.clientsByMember.delete(String(member.id))
                }
            })
            .listen('.update', (event) => this.receive(event))
            .listen('.reset', () => this.reload())
            .listenForWhisper('awareness', (event) => {
                const clients = this.clientsByMember.get(String(event.m)) ?? new Set()
                clients.add(event.c)
                this.clientsByMember.set(String(event.m), clients)
                applyAwarenessUpdate(this.awareness, fromBase64(event.a), REMOTE)
            })

        channel.error?.(() => {
            this.view.status = 'offline'
        })

        const pusher = window.Echo.connector?.pusher
        this.onState = ({ previous, current }) => {
            if (current === 'connected' && previous !== 'connected' && previous !== 'initialized') {
                this.view.status = 'live'
                this.catchUp().catch(() => {})
                this.scheduleFlush()
            } else if (current === 'unavailable' || current === 'failed' || current === 'disconnected') {
                this.view.status = 'offline'
            }
        }
        pusher?.connection?.bind('state_change', this.onState)
    }

    async receive(event) {
        if (event.g !== this.generation) {
            return this.reload()
        }

        if (event.u) {
            Y.applyUpdate(this.doc, fromBase64(event.u), REMOTE)

            return
        }

        // Too big for one event: fetch it.
        await this.catchUp()
    }

    async catchUp() {
        const body = await this.request('GET', this.config.urls.show, { after: this.cursor })

        if (body.generation !== this.generation) {
            return this.reload()
        }

        this.load(body)
    }

    // The saved value was changed outside the editor, so the document
    // starts again from it.
    async reload() {
        if (this.reloading) {
            return
        }

        this.reloading = true
        this.view.status = 'reloading'

        for (let attempt = 0; attempt < 5 && !this.closed; attempt++) {
            await sleep(800 * (attempt + 1))

            const body = await this.request('GET', this.config.urls.show).catch(() => null)

            if (body?.seeded) {
                this.closed = true
                // Already started again from the new value: do not compare
                // it with the value this page was loaded with.
                this.config.source = null
                this.view.destroy()
                this.view.status = 'connecting'
                this.view.$nextTick(() => this.view.init())

                return
            }
        }

        this.reloading = false
    }

    scheduleFlush() {
        if (this.flushTimer === null) {
            this.flushTimer = setTimeout(() => this.flush(), FLUSH_MS)
        }
    }

    async flush() {
        this.flushTimer = null

        if (this.sending || !this.pending.length || this.closed) {
            return
        }

        this.sending = true
        const batch = this.pending
        this.pending = []
        const update = batch.length === 1 ? batch[0] : Y.mergeUpdates(batch)

        try {
            const body = await this.request('POST', this.config.urls.update, {
                generation: this.generation,
                update: toBase64(update),
            })

            this.view.status = 'live'

            if (body.compact) {
                this.compact().catch(() => {})
            }
        } catch (error) {
            if (error.status === 409) {
                this.sending = false

                return this.reload()
            }

            // Keep it and try again; Yjs updates can be sent twice safely.
            this.pending.unshift(update)
            this.view.status = 'offline'
            this.sending = false
            setTimeout(() => this.scheduleFlush(), 2000)

            return
        }

        this.sending = false

        if (this.pending.length) {
            this.scheduleFlush()
        }
    }

    // Replaces the stored history with one snapshot when the server asks.
    async compact() {
        await this.catchUp()
        await this.request('POST', this.config.urls.compact, {
            generation: this.generation,
            upto: this.cursor,
            update: toBase64(Y.encodeStateAsUpdate(this.doc)),
        })
    }

    scheduleAwareness() {
        if (this.awarenessTimer !== null || !this.channel) {
            return
        }

        this.awarenessTimer = setTimeout(() => {
            this.awarenessTimer = null

            if (!this.awarenessDirty || this.closed) {
                return
            }

            this.awarenessDirty = false
            this.channel.whisper('awareness', {
                m: this.config.user.id,
                c: this.doc.clientID,
                a: toBase64(encodeAwarenessUpdate(this.awareness, [this.doc.clientID])),
            })
        }, AWARENESS_MS)
    }

    async request(method, url, data = {}) {
        const target = new URL(url, window.location.href)
        const options = {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        }

        if (method === 'GET') {
            for (const [key, value] of Object.entries(data)) {
                if (value !== undefined && value !== null) {
                    target.searchParams.set(key, value)
                }
            }
        } else {
            options.headers['Content-Type'] = 'application/json'
            options.headers['X-CSRF-TOKEN'] =
                document.querySelector('meta[name="csrf-token"]')?.content ?? ''
            options.body = JSON.stringify(data)
        }

        const socketId = window.Echo?.socketId?.()

        if (socketId) {
            options.headers['X-Socket-ID'] = socketId
        }

        const response = await fetch(target, options)

        if (!response.ok) {
            const error = new Error(`HTTP ${response.status}`)
            error.status = response.status
            throw error
        }

        return response.json()
    }

    close() {
        if (this.pending.length && !this.closed) {
            this.flushNow()
        }

        this.closed = true
        clearTimeout(this.flushTimer)
        clearTimeout(this.awarenessTimer)
        this.doc.off('update', this.onDocUpdate)
        this.awareness.off('update', this.onAwarenessUpdate)

        if (this.channel) {
            removeAwarenessStates(this.awareness, [this.doc.clientID], 'local')
            window.Echo.leave(this.config.channel)
        }

        window.Echo?.connector?.pusher?.connection?.unbind('state_change', this.onState)
        this.awareness.destroy()
        this.doc.destroy()
    }

    // Best effort for typing that is still waiting when the page goes away.
    flushNow() {
        const update = Y.mergeUpdates(this.pending)
        this.pending = []

        fetch(this.config.urls.update, {
            method: 'POST',
            keepalive: true,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({ generation: this.generation, update: toBase64(update) }),
        }).catch(() => {})
    }
}

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms))
}

function toBase64(bytes) {
    let binary = ''

    for (let i = 0; i < bytes.length; i += 0x8000) {
        binary += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000))
    }

    return btoa(binary)
}

function fromBase64(text) {
    const binary = atob(text)
    const bytes = new Uint8Array(binary.length)

    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i)
    }

    return bytes
}
