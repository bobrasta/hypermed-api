<?php

namespace Tests\Feature;

use App\Models\EmailAccount;
use App\Models\SyncedEmail;
use App\Models\User;
use App\Services\Email\MailFolders;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Spam / Trash / Archive / Flagged tabs and moving mail between them.
// Moves of mail on the server run in EmailAttachmentsTest's GreenMail setup
// (testMoveOnServer below, opt-in); these use copies kept only here.
class EmailFoldersTest extends TestCase
{
    use DatabaseTransactions;

    private User $owner;
    private EmailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::forceCreate(['name' => 'Mail Owner', 'email' => 'folders-owner@example.com',
            'password' => bcrypt('x'), 'role' => 'admin', 'is_active' => true]);
        $host = env('GREENMAIL') ? '127.0.0.1' : 'mail.invalid';
        $this->account = EmailAccount::create(['user_id' => $this->owner->id, 'label' => 'test', 'imap_host' => $host, 'imap_port' => 3143,
            'imap_encryption' => 'none', 'smtp_host' => $host, 'smtp_port' => 3025, 'smtp_encryption' => 'none',
            'username' => 'diana', 'password' => 'pw', 'from_name' => 'Diana', 'from_email' => 'diana@local.test',
            'is_default' => true, 'is_active' => true]);
        Sanctum::actingAs($this->owner);
    }

    private function email(string $folder, array $extra = []): SyncedEmail
    {
        return SyncedEmail::create($extra + [
            'email_account_id' => $this->account->id, 'uid' => uniqid('sent_', true), 'folder' => $folder,
            'subject' => "In {$folder}", 'from_email' => 'someone@example.com', 'to_addresses' => [],
            'is_read' => true, 'email_date' => now(),
        ]);
    }

    private function subjects(string $tab): array
    {
        return collect($this->getJson("/api/v1/emails/folder/{$tab}")->assertOk()->json('data'))->pluck('subject')->sort()->values()->all();
    }

    public function test_kind_of_server_folder_names(): void
    {
        $this->assertSame('spam', MailFolders::kindOf('INBOX.Junk'));
        $this->assertSame('spam', MailFolders::kindOf('Spam'));
        $this->assertSame('trash', MailFolders::kindOf('INBOX/Deleted Items'));
        $this->assertSame('archive', MailFolders::kindOf('INBOX.Archive'));
        $this->assertSame('sent', MailFolders::kindOf('INBOX.Sent'));
        $this->assertSame('inbox', MailFolders::kindOf('INBOX'));
        $this->assertNull(MailFolders::kindOf('INBOX.Projects'));
    }

    public function test_tabs_list_their_folders_whatever_the_server_calls_them(): void
    {
        $this->email('INBOX');
        $this->email('INBOX.Junk');
        $this->email('Spam');
        $this->email('INBOX.Trash', ['is_flagged' => true]);
        $this->email('INBOX.Archive', ['is_flagged' => true]);
        $this->email('INBOX', ['subject' => 'Starred', 'is_flagged' => true]);

        $this->assertSame(['In INBOX.Junk', 'In Spam'], $this->subjects('spam'));
        $this->assertSame(['In INBOX.Trash'], $this->subjects('trash'));
        $this->assertSame(['In INBOX.Archive'], $this->subjects('archive'));
        // Flagged: every folder except Spam and Trash.
        $this->assertSame(['In INBOX.Archive', 'Starred'], $this->subjects('flagged'));
    }

    public function test_spam_not_spam_trash_restore_for_mail_kept_here(): void
    {
        $e = $this->email('INBOX');
        $this->postJson("/api/v1/emails/{$e->id}/move", ['to' => 'spam'])->assertOk()->assertJsonPath('data.folder', 'Junk');
        $this->assertSame(['In INBOX'], $this->subjects('spam'));
        $this->postJson("/api/v1/emails/{$e->id}/move", ['to' => 'inbox'])->assertOk()->assertJsonPath('data.folder', 'INBOX');
        $this->postJson("/api/v1/emails/{$e->id}/move", ['to' => 'trash'])->assertOk()->assertJsonPath('data.folder', 'Trash');
        $this->postJson("/api/v1/emails/{$e->id}/move", ['to' => 'restore'])->assertOk()->assertJsonPath('data.folder', 'INBOX');

        // Own mail restores to Sent.
        $sent = $this->email('Sent', ['from_email' => 'diana@local.test']);
        $this->postJson("/api/v1/emails/{$sent->id}/move", ['to' => 'trash'])->assertOk();
        $this->postJson("/api/v1/emails/{$sent->id}/move", ['to' => 'restore'])->assertOk()->assertJsonPath('data.folder', 'Sent');

        $this->postJson("/api/v1/emails/{$e->id}/move", ['to' => 'elsewhere'])->assertStatus(422);
    }

    public function test_only_the_owner_moves(): void
    {
        $e = $this->email('INBOX');
        $other = User::forceCreate(['name' => 'Other', 'email' => 'folders-other@example.com',
            'password' => bcrypt('x'), 'role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($other);
        $this->postJson("/api/v1/emails/{$e->id}/move", ['to' => 'spam'])->assertForbidden();
    }

    /** On a real IMAP server: spam, then not spam; the next syncs fetch no duplicate. */
    public function test_move_on_server(): void
    {
        if (! env('GREENMAIL') || config('database.default') !== 'pgsql') {
            $this->markTestSkipped('needs GREENMAIL=1, a GreenMail server and Postgres');
        }
        $client = \App\Services\Email\EmailAttachmentStore::client($this->account);
        $client->connect();
        foreach ($client->getFolders(false) as $f) {
            foreach ($f->messages()->all()->get() as $m) {
                $m->delete(false);
            }
            $client->expunge();
        }
        $client->getConnection()->appendMessage('INBOX',
            "From: Spammer <spam@example.com>\r\nTo: diana@local.test\r\nSubject: Win a prize\r\nMessage-ID: <p1@example.com>\r\n\r\nClick here\r\n");
        $client->disconnect();

        $this->postJson('/api/v1/emails/sync', ['folder' => 'INBOX'])->assertOk()->assertJsonPath('data.synced', 1);
        $e = SyncedEmail::where('email_account_id', $this->account->id)->where('subject', 'Win a prize')->firstOrFail();

        $this->postJson("/api/v1/emails/{$e->id}/move", ['to' => 'spam'])->assertOk();
        $e->refresh();
        $this->assertSame('spam', MailFolders::kindOf($e->folder));
        $this->postJson('/api/v1/emails/sync', ['folder' => 'spam'])->assertOk()->assertJsonPath('data.synced', 0);
        $this->postJson('/api/v1/emails/sync', ['folder' => 'INBOX'])->assertOk()->assertJsonPath('data.synced', 0);
        $this->assertSame(['Win a prize'], $this->subjects('spam'));

        $this->postJson("/api/v1/emails/{$e->id}/move", ['to' => 'inbox'])->assertOk()->assertJsonPath('data.folder', 'INBOX');
        $this->postJson('/api/v1/emails/sync', ['folder' => 'INBOX'])->assertOk()->assertJsonPath('data.synced', 0);
        $this->assertSame(1, SyncedEmail::where('email_account_id', $this->account->id)->count());
    }
}
