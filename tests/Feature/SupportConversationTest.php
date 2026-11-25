<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Notification;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_participant_can_start_a_support_conversation(): void
    {
        [$user, $token] = $this->participant();

        $response = $this->withToken($token)->post('/api/support/conversation', [
            'category' => 'COMPLAINT',
            'subject' => 'Missing delivery',
            'message' => 'My rice has not arrived.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'OPEN')
            ->assertJsonPath('data.category', 'COMPLAINT')
            ->assertJsonCount(2, 'data.messages');

        $conversation = SupportConversation::firstOrFail();
        $this->assertSame($user->id, $conversation->user_id);
        $this->assertStringStartsWith('SUP-', $conversation->reference);
        $this->assertSame('SYSTEM', SupportMessage::orderBy('id')->first()->sender_type);
    }

    public function test_new_messages_join_the_open_thread_instead_of_creating_another(): void
    {
        [$user, $token] = $this->participant();

        $this->withToken($token)->post('/api/support/conversation', [
            'message' => 'I need help with a deposit.',
        ])->assertCreated();

        $this->withToken($token)->post('/api/support/conversation', [
            'message' => 'Adding more detail.',
        ])->assertOk()->assertJsonCount(3, 'data.messages');

        $this->assertSame(1, SupportConversation::count());
        $this->assertSame(3, SupportMessage::count());
    }

    public function test_participant_can_fetch_and_reply_to_their_thread(): void
    {
        [$user, $token] = $this->participant();

        $this->withToken($token)->post('/api/support/conversation', [
            'message' => 'Question about my wallet.',
        ]);

        $this->withToken($token)->get('/api/support/conversation')
            ->assertOk()
            ->assertJsonPath('data.userName', $user->name);

        $this->withToken($token)->post('/api/support/conversation/messages', [
            'message' => 'Any update?',
        ])->assertCreated()->assertJsonPath('data.messages.2.senderType', 'USER');
    }

    public function test_empty_messages_are_rejected(): void
    {
        [, $token] = $this->participant();

        $this->withToken($token)->post('/api/support/conversation', [
            'message' => '',
        ])->assertUnprocessable();
    }

    public function test_staff_can_reply_and_the_participant_is_notified(): void
    {
        [$user, $userToken] = $this->participant();
        [, $staffToken] = $this->staff();

        $started = $this->withToken($userToken)->post('/api/support/conversation', [
            'message' => 'Please call me about my refund.',
        ]);

        $id = $started->json('data.id');

        $this->withToken($staffToken)->post("/api/admin/support/{$id}/messages", [
            'message' => 'We have approved your refund.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.messages.2.senderType', 'SUPPORT');

        $this->assertSame(1, Notification::where('user_id', $user->id)->count());
    }

    public function test_participants_cannot_reach_the_staff_inbox(): void
    {
        [, $token] = $this->participant();

        $this->withToken($token)->get('/api/admin/support')->assertForbidden();
    }

    public function test_closed_conversations_cannot_receive_participant_replies(): void
    {
        [$user, $userToken] = $this->participant();
        [, $staffToken] = $this->staff();

        $started = $this->withToken($userToken)->post('/api/support/conversation', [
            'message' => 'Issue resolved?',
        ]);

        $id = $started->json('data.id');

        $this->withToken($staffToken)->post("/api/admin/support/{$id}/close")->assertOk();

        $this->withToken($userToken)->post('/api/support/conversation/messages', [
            'message' => 'One more thing.',
        ])->assertNotFound();

        $this->assertSame('CLOSED', SupportConversation::firstOrFail()->status);
    }

    /** @return array{0: User, 1: string} */
    private function participant(): array
    {
        $user = User::factory()->create(['role' => 'PARTICIPANT']);
        [, $token] = ApiToken::issue($user);

        return [$user, $token];
    }

    /** @return array{0: User, 1: string} */
    private function staff(): array
    {
        $user = User::factory()->create(['role' => 'OPS', 'status' => 'ACTIVE']);
        [, $token] = ApiToken::issue($user);

        return [$user, $token];
    }
}
