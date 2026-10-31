@php
    use Filament\Support\Facades\FilamentAsset;
    use Hibira\FilamentCollab\Forms\Components\CollaborativeEditor;

    $statePath = $getStatePath();
    $collab = $getCollabConfig();
    $isDisabled = $isDisabled();
    $buttons = $getToolbarButtons();
    $minHeight = $getMinHeight();
    $src = FilamentAsset::getAlpineComponentSrc('hibira-collab-editor', 'hibira-io/filament-collab');
    $asyncAlpine = CollaborativeEditor::usesAsyncAlpine();

    $icons = [
        'bold' => 'heroicon-m-bold',
        'italic' => 'heroicon-m-italic',
        'strike' => 'heroicon-m-strikethrough',
        'code' => 'heroicon-m-code-bracket',
        'link' => 'heroicon-m-link',
        'h2' => 'heroicon-m-h2',
        'h3' => 'heroicon-m-h3',
        'bulletList' => 'heroicon-m-list-bullet',
        'orderedList' => 'heroicon-m-numbered-list',
        'blockquote' => 'heroicon-m-chat-bubble-bottom-center-text',
        'codeBlock' => 'heroicon-m-code-bracket-square',
        'undo' => 'heroicon-m-arrow-uturn-left',
        'redo' => 'heroicon-m-arrow-uturn-right',
    ];
    $commands = [
        'bold' => 'toggleBold', 'italic' => 'toggleItalic', 'strike' => 'toggleStrike', 'code' => 'toggleCode',
        'link' => 'link', 'h2' => 'h2', 'h3' => 'h3',
        'bulletList' => 'toggleBulletList', 'orderedList' => 'toggleOrderedList',
        'blockquote' => 'toggleBlockquote', 'codeBlock' => 'toggleCodeBlock',
        'undo' => 'undo', 'redo' => 'redo',
    ];
    $groups = [['bold', 'italic', 'strike', 'code', 'link'], ['h2', 'h3'], ['bulletList', 'orderedList', 'blockquote', 'codeBlock'], ['undo', 'redo']];
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        @if ($asyncAlpine)
            ax-load
            ax-load-src="{{ $src }}"
            x-ignore
        @else
            x-load
            x-load-src="{{ $src }}"
        @endif
        x-data="hibiraCollabEditor({
            state: $wire.{{ $applyStateBindingModifiers("\$entangle('{$statePath}')") }},
            collab: @js($collab),
            placeholder: @js($getPlaceholder()),
            disabled: @js($isDisabled),
            labels: @js(__('hibira-collab::editor')),
        })"
        wire:ignore
        {{ $attributes->merge($getExtraAttributes(), escape: false)->class([
            'hibira-collab',
            'hibira-collab--disabled' => $isDisabled,
            'hibira-collab--rgb' => $asyncAlpine,
        ]) }}
    >
        <div class="hibira-collab__bar">
            @unless ($isDisabled)
                <div class="hibira-collab__toolbar" role="toolbar" aria-label="{{ __('hibira-collab::editor.toolbar') }}">
                    @foreach ($groups as $group)
                        @php($visible = array_values(array_intersect($group, $buttons)))
                        @if (count($visible))
                            <div class="hibira-collab__group">
                                @foreach ($visible as $button)
                                    <button
                                        type="button"
                                        class="hibira-collab__button"
                                        x-on:click="run(@js($commands[$button]))"
                                        @if (in_array($button, ['undo', 'redo']))
                                            x-bind:disabled="! {{ $button === 'undo' ? 'canUndo' : 'canRedo' }}"
                                        @else
                                            x-bind:class="{ 'hibira-collab__button--active': active[@js($button)] }"
                                            x-bind:aria-pressed="active[@js($button)] ? 'true' : 'false'"
                                        @endif
                                        x-tooltip="{ content: @js(__("hibira-collab::editor.buttons.{$button}")), theme: $store.theme }"
                                        aria-label="{{ __("hibira-collab::editor.buttons.{$button}") }}"
                                    >
                                        <x-filament::icon :icon="$icons[$button]" class="hibira-collab__icon" />
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    @endforeach
                </div>
            @endunless

            @if ($collab)
                <div class="hibira-collab__presence">
                    <div class="hibira-collab__avatars">
                        <template x-for="member in members.slice(0, 5)" x-bind:key="member.id">
                            <span
                                class="hibira-collab__avatar"
                                x-bind:style="{ '--hibira-collab-color': member.color }"
                                x-tooltip="{ content: member.name, theme: $store.theme }"
                            >
                                <img x-bind:src="member.avatar" x-bind:alt="member.name" />
                            </span>
                        </template>
                        <span
                            class="hibira-collab__more"
                            x-show="members.length > 5"
                            x-text="'+' + (members.length - 5)"
                        ></span>
                    </div>
                    <span
                        class="hibira-collab__status"
                        x-bind:data-status="status"
                        x-tooltip="{ content: statusLabel(), theme: $store.theme }"
                        x-bind:aria-label="statusLabel()"
                        role="status"
                    ></span>
                </div>
            @endif
        </div>

        @unless ($isDisabled)
            <div
                class="hibira-collab__link"
                x-show="linkOpen"
                x-cloak
                x-on:keydown.escape.prevent.stop="closeLink()"
            >
                <input
                    type="url"
                    x-ref="link"
                    x-model="linkHref"
                    x-on:keydown.enter.prevent="applyLink()"
                    class="hibira-collab__link-input"
                    placeholder="https://"
                    aria-label="{{ __('hibira-collab::editor.link.label') }}"
                />
                <button type="button" class="hibira-collab__button" x-on:click="applyLink()" x-tooltip="{ content: @js(__('hibira-collab::editor.link.apply')), theme: $store.theme }" aria-label="{{ __('hibira-collab::editor.link.apply') }}">
                    <x-filament::icon icon="heroicon-m-check" class="hibira-collab__icon" />
                </button>
                <button type="button" class="hibira-collab__button" x-on:click="removeLink()" x-show="active.link" x-tooltip="{ content: @js(__('hibira-collab::editor.link.remove')), theme: $store.theme }" aria-label="{{ __('hibira-collab::editor.link.remove') }}">
                    <x-filament::icon icon="heroicon-m-link-slash" class="hibira-collab__icon" />
                </button>
                <button type="button" class="hibira-collab__button" x-on:click="closeLink()" x-tooltip="{ content: @js(__('hibira-collab::editor.link.cancel')), theme: $store.theme }" aria-label="{{ __('hibira-collab::editor.link.cancel') }}">
                    <x-filament::icon icon="heroicon-m-x-mark" class="hibira-collab__icon" />
                </button>
            </div>
        @endunless

        <div
            class="hibira-collab__editor"
            x-ref="editor"
            @if ($minHeight) style="--hibira-collab-min-height: {{ e($minHeight) }}" @endif
        ></div>
    </div>
</x-dynamic-component>
