<?php

namespace Tests\Feature;

use App\Models\PiggyBank;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Tests\TestCase;

class PiggyBankTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setBaseRoute('piggy-bank');
        $this->setBaseModel(PiggyBank::class);
    }

    public function test_guest_cannot_access_resource(): void
    {
        $this->get(route("{$this->base_route}.index"))->assertRedirectToRoute('login');
        $this->get(route("{$this->base_route}.create"))->assertRedirectToRoute('login');
        $this->post(route("{$this->base_route}.store"))->assertRedirectToRoute('login');

        $user = User::factory()->create();
        $piggyBank = $this->createForUser($user, $this->base_model);

        $this->get(route("{$this->base_route}.show", $piggyBank))->assertRedirectToRoute('login');
        $this->get(route("{$this->base_route}.edit", $piggyBank))->assertRedirectToRoute('login');
        $this->patch(route("{$this->base_route}.update", $piggyBank))->assertRedirectToRoute('login');
        $this->delete(route("{$this->base_route}.destroy", $piggyBank))->assertRedirectToRoute('login');
    }

    public function test_unverified_user_cannot_access_resource(): void
    {
        /** @var Authenticatable $user_unverified */
        $user_unverified = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $this->actingAs($user_unverified)->get(route("{$this->base_route}.index"))->assertRedirectToRoute('verification.notice');
        $this->actingAs($user_unverified)->get(route("{$this->base_route}.create"))->assertRedirectToRoute('verification.notice');
        $this->actingAs($user_unverified)->post(route("{$this->base_route}.store"))->assertRedirectToRoute('verification.notice');

        $user = User::factory()->create();
        $piggyBank = $this->createForUser($user, $this->base_model);

        $this->actingAs($user_unverified)->get(route("{$this->base_route}.show", $piggyBank))->assertRedirectToRoute('verification.notice');
        $this->actingAs($user_unverified)->get(route("{$this->base_route}.edit", $piggyBank))->assertRedirectToRoute('verification.notice');
        $this->actingAs($user_unverified)->patch(route("{$this->base_route}.update", $piggyBank))->assertRedirectToRoute('verification.notice');
        $this->actingAs($user_unverified)->delete(route("{$this->base_route}.destroy", $piggyBank))->assertRedirectToRoute('verification.notice');
    }

    public function test_user_cannot_access_other_users_resource(): void
    {
        $user1 = User::factory()->create();
        $piggyBank = $this->createForUser($user1, $this->base_model);

        /** @var Authenticatable $user2 */
        $user2 = User::factory()->create();

        $this->actingAs($user2)->get(route("{$this->base_route}.show", $piggyBank))->assertStatus(Response::HTTP_FORBIDDEN);
        $this->actingAs($user2)->get(route("{$this->base_route}.edit", $piggyBank))->assertStatus(Response::HTTP_FORBIDDEN);
        $this->actingAs($user2)->patch(route("{$this->base_route}.update", $piggyBank))->assertStatus(Response::HTTP_FORBIDDEN);
        $this->actingAs($user2)->delete(route("{$this->base_route}.destroy", $piggyBank))->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_user_can_view_list_of_piggy_banks(): void
    {
        $user = User::factory()->create();
        $this->createForUser($user, $this->base_model, [], 3);

        $response = $this->actingAs($user)->get(route("{$this->base_route}.index"));

        $response->assertStatus(Response::HTTP_OK);
        $response->assertViewIs("{$this->base_route}.index");
    }

    public function test_user_can_access_create_form(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route("{$this->base_route}.create"));

        $response->assertStatus(Response::HTTP_OK);
        $response->assertViewIs("{$this->base_route}.form");
    }

    public function test_user_cannot_create_a_piggy_bank_with_missing_data(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(
            route("{$this->base_route}.store"),
            ['name' => '']
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'target_amount']);
    }

    public function test_user_can_create_a_piggy_bank(): void
    {
        $user = User::factory()->create();

        $this->assertCreateForUser($user);
    }

    public function test_user_can_view_a_piggy_bank(): void
    {
        $user = User::factory()->create();
        $piggyBank = $this->createForUser($user, $this->base_model);

        $response = $this->actingAs($user)->get(route("{$this->base_route}.show", $piggyBank));

        $response->assertStatus(Response::HTTP_OK);
        $response->assertViewIs("{$this->base_route}.show");
    }

    public function test_user_can_edit_an_existing_piggy_bank(): void
    {
        $user = User::factory()->create();
        $piggyBank = $this->createForUser($user, $this->base_model);

        $response = $this->actingAs($user)->get(route("{$this->base_route}.edit", $piggyBank));

        $response->assertStatus(Response::HTTP_OK);
        $response->assertViewIs("{$this->base_route}.form");
    }

    public function test_user_cannot_update_a_piggy_bank_with_missing_data(): void
    {
        $user = User::factory()->create();
        $piggyBank = $this->createForUser($user, $this->base_model);

        $response = $this->actingAs($user)->patchJson(
            route("{$this->base_route}.update", $piggyBank),
            ['name' => '', 'target_amount' => '']
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'target_amount']);
    }

    public function test_user_can_update_a_piggy_bank(): void
    {
        $user = User::factory()->create();
        $piggyBank = $this->createForUser($user, $this->base_model);

        $response = $this->actingAs($user)->patchJson(
            route("{$this->base_route}.update", $piggyBank),
            [
                'name' => 'Updated Name',
                'target_amount' => '5000.00',
                'current_amount' => '1500.00',
            ]
        );

        $response->assertRedirect($this->base_route);
        $notifications = session('notification_collection');
        $successNotificationExists = collect($notifications)
            ->contains(fn ($notification) => $notification['type'] === 'success');
        $this->assertTrue($successNotificationExists);
    }

    public function test_user_can_delete_an_existing_piggy_bank(): void
    {
        $user = User::factory()->create();

        $this->assertDestroyWithUser($user);
    }
}
