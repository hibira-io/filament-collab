<?php

namespace Hibira\FilamentCollab\Tests;

use Filament\Facades\Filament;
use Hibira\FilamentCollab\Collab;
use Hibira\FilamentCollab\Models\Document;
use Hibira\FilamentCollab\Models\Update;
use Hibira\FilamentCollab\Tests\Fixtures\AdminPanelProvider;
use Hibira\FilamentCollab\Tests\Fixtures\Post;
use Hibira\FilamentCollab\Tests\Fixtures\User;
use Illuminate\Support\Facades\Broadcast;
use Livewire\Livewire;

class CollabTest extends TestCase
{
    protected User $ada;

    protected User $bo;

    protected Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ada = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
        $this->bo = User::query()->create(['name' => 'Bo', 'email' => 'bo@example.com', 'password' => 'x']);
        $this->post = Post::query()->create(['title' => 'Launch', 'body' => '<p>Hello</p>']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_edit_page_joins_the_shared_document(): void
    {
        $key = $this->key();

        $this->actingAs($this->ada)
            ->get("/admin/posts/{$this->post->id}/edit")
            ->assertOk()
            ->assertSee('hibiraCollabEditor', false)
            ->assertSee("hibira-collab.admin.{$key}", false)
            ->assertSee(Collab::hash('<p>Hello</p>'), false);

        $this->assertSame(['edit' => true], app(Collab::class)->grantFor('admin', $key, $this->ada));
        $this->assertNull(app(Collab::class)->grantFor('admin', $key, $this->bo));
    }

    public function test_create_page_has_a_plain_editor(): void
    {
        $this->actingAs($this->ada)
            ->get('/admin/posts/create')
            ->assertOk()
            ->assertSee('hibiraCollabEditor', false)
            ->assertSee('collab: null', false);
    }

    public function test_without_an_app_key_the_editor_is_plain(): void
    {
        config()->set('hibira.key', '');

        $this->actingAs($this->ada)
            ->get("/admin/posts/{$this->post->id}/edit")
            ->assertOk()
            ->assertSee('collab: null', false)
            ->assertDontSee('hibira-collab.admin.', false);
    }

    public function test_documents_need_a_grant(): void
    {
        $key = $this->key();

        $this->actingAs($this->bo)->getJson($this->url($key))->assertForbidden();
        $this->actingAs($this->bo)->postJson($this->url($key, 'updates'), ['generation' => 1, 'update' => 'AA=='])->assertForbidden();
    }

    public function test_first_editor_seeds_the_document_and_others_load_it(): void
    {
        $key = $this->open($this->ada);
        $this->open($this->bo);
        $source = Collab::hash('<p>Hello</p>');

        $this->actingAs($this->ada)->getJson($this->url($key).'?source='.$source)
            ->assertOk()
            ->assertJson(['generation' => 1, 'seeded' => false, 'updates' => []]);

        $id = $this->actingAs($this->ada)->postJson($this->url($key, 'seed'), ['generation' => 1, 'update' => 'c2VlZA=='])
            ->assertOk()
            ->json('id');

        // Bo raced Ada to it and loses.
        $this->actingAs($this->bo)->postJson($this->url($key, 'seed'), ['generation' => 1, 'update' => 'bG9zZXI='])
            ->assertStatus(409);

        $this->actingAs($this->bo)->getJson($this->url($key).'?source='.$source)
            ->assertOk()
            ->assertJson(['generation' => 1, 'seeded' => true, 'updates' => [['id' => $id, 'u' => 'c2VlZA==']]]);
    }

    public function test_changes_are_stored_and_relayed_to_the_others(): void
    {
        $key = $this->seeded();

        $this->actingAs($this->ada)
            ->postJson($this->url($key, 'updates'), ['generation' => 1, 'update' => 'dHlwZWQ='], ['X-Socket-ID' => '123.456'])
            ->assertOk()
            ->assertJson(['compact' => false]);

        $sent = $this->realtime->events('update')[0];
        $this->assertSame(["presence-hibira-collab.admin.{$key}"], $sent['channels']);
        $this->assertSame('dHlwZWQ=', $sent['data']['u']);
        $this->assertSame(1, $sent['data']['g']);
        $this->assertSame('123.456', $sent['except']);

        $after = Update::query()->min('id');
        $this->actingAs($this->ada)->getJson($this->url($key)."?after={$after}")
            ->assertJsonCount(1, 'updates')
            ->assertJsonPath('updates.0.u', 'dHlwZWQ=');
    }

    public function test_big_changes_are_announced_without_their_content(): void
    {
        $key = $this->seeded();
        $big = base64_encode(random_bytes(6000));

        $id = $this->actingAs($this->ada)->postJson($this->url($key, 'updates'), ['generation' => 1, 'update' => $big])
            ->assertOk()
            ->json('id');

        $sent = $this->realtime->events('update')[0]['data'];
        $this->assertSame(['id' => $id, 'g' => 1], $sent);
        $this->assertLessThan(4096, strlen(json_encode($sent)));
    }

    public function test_bad_socket_ids_and_updates_are_refused(): void
    {
        $key = $this->seeded();

        $this->actingAs($this->ada)->postJson($this->url($key, 'updates'), ['generation' => 1, 'update' => 'not base64!'])
            ->assertStatus(422);

        $this->actingAs($this->ada)
            ->postJson($this->url($key, 'updates'), ['generation' => 1, 'update' => 'AA=='], ['X-Socket-ID' => 'evil'])
            ->assertOk();
        $this->assertNull($this->realtime->events('update')[0]['except']);
    }

    public function test_read_only_viewers_cannot_change_the_document(): void
    {
        $key = $this->seeded();
        app(Collab::class)->grant('admin', $key, $this->bo, canEdit: false);

        $this->actingAs($this->bo)->getJson($this->url($key))->assertOk();
        $this->actingAs($this->bo)->postJson($this->url($key, 'updates'), ['generation' => 1, 'update' => 'AA=='])->assertForbidden();
        $this->actingAs($this->bo)->postJson($this->url($key, 'compact'), ['generation' => 1, 'upto' => 1, 'update' => 'AA=='])->assertForbidden();
    }

    public function test_a_value_changed_outside_the_editor_starts_a_new_generation(): void
    {
        $key = $this->seeded();
        $this->post->update(['body' => '<p>Imported</p>']);

        $this->actingAs($this->ada)->getJson($this->url($key).'?source='.Collab::hash('<p>Imported</p>'))
            ->assertOk()
            ->assertJson(['generation' => 2, 'seeded' => false, 'updates' => []]);

        $this->assertSame(['g' => 2], $this->realtime->events('reset')[0]['data']);

        // An editor still on the old generation must reload.
        $this->actingAs($this->ada)->postJson($this->url($key, 'updates'), ['generation' => 1, 'update' => 'AA=='])
            ->assertStatus(409);
    }

    public function test_saving_the_record_keeps_the_document(): void
    {
        $key = $this->seeded();
        $this->actingAs($this->ada);

        Livewire::test($this->editPage(), ['record' => $this->post->getRouteKey()])
            ->set('data.body', '<p>Hello, team</p>')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('<p>Hello, team</p>', $this->post->refresh()->body);
        $this->assertSame(Collab::hash('<p>Hello, team</p>'), Document::query()->where('key', $key)->value('source_hash'));

        $this->getJson($this->url($key).'?source='.Collab::hash('<p>Hello, team</p>'))
            ->assertJson(['generation' => 1, 'seeded' => true]);
        $this->assertSame([], $this->realtime->events('reset'));
    }

    public function test_long_histories_are_compacted_into_a_snapshot(): void
    {
        config()->set('hibira-collab.compact_after', 10);
        $key = $this->seeded();

        $compact = false;
        for ($i = 0; $i < 10; $i++) {
            $compact = $this->actingAs($this->ada)->postJson($this->url($key, 'updates'), ['generation' => 1, 'update' => 'AA=='])->json('compact');
        }
        $this->assertTrue($compact);

        $upto = (int) Update::query()->max('id');
        $this->actingAs($this->ada)->postJson($this->url($key, 'compact'), ['generation' => 1, 'upto' => $upto, 'update' => 'c25hcA=='])
            ->assertOk();

        $this->actingAs($this->ada)->getJson($this->url($key))
            ->assertJsonCount(1, 'updates')
            ->assertJsonPath('updates.0.u', 'c25hcA==');

        // An editor that had seen half of them still catches up.
        $this->actingAs($this->ada)->getJson($this->url($key).'?after='.($upto - 5))
            ->assertJsonCount(1, 'updates');

        $this->assertFalse($this->actingAs($this->ada)->postJson($this->url($key, 'updates'), ['generation' => 1, 'update' => 'AA=='])->json('compact'));
    }

    public function test_presence_channel_admits_granted_users_with_a_color(): void
    {
        $key = $this->open($this->ada);
        $channel = Collab::channel('admin', $key);
        $callback = Broadcast::driver()->getChannels()->get(Collab::channel('admin', '{document}'));

        $this->assertNotNull($callback, 'channel registered');

        $member = $callback($this->ada, $key);
        $this->assertSame('Ada', $member['name']);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $member['color']);
        $this->assertFalse($callback($this->bo, $key));
        $this->assertStringStartsWith('hibira-collab.admin.', $channel);
    }

    protected function key(): string
    {
        return Collab::key(Post::class.':'.$this->post->id.':data.body');
    }

    protected function url(string $key, string $action = ''): string
    {
        return rtrim("/admin/hibira-collab/{$key}/{$action}", '/');
    }

    protected function open(User $user): string
    {
        $this->actingAs($user)->get("/admin/posts/{$this->post->id}/edit")->assertOk();

        return $this->key();
    }

    protected function seeded(): string
    {
        $key = $this->open($this->ada);
        app(Collab::class)->open($key, Collab::hash('<p>Hello</p>'));
        app(Collab::class)->seed($key, 1, 'c2VlZA==');

        return $key;
    }

    protected function editPage(): string
    {
        $resource = AdminPanelProvider::postResource();

        return $resource::getPages()['edit']->getPage();
    }
}
