<?php

namespace Tests\Feature\Settings;

use App\Enums\UserRole;
use App\Livewire\Settings\TranslationQueuePage;
use App\Models\AutoReply;
use App\Models\TranslationJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

class TranslationQueuePageTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        return $admin;
    }

    public function test_route_is_registered_and_admin_can_render_queue(): void
    {
        $this->actingAdmin();

        $this->assertTrue(Route::has('admin.settings.language.translate-queue'));

        $this->get(route('admin.settings.language.translate-queue'))
            ->assertOk()
            ->assertSee('Языки')
            ->assertSee('Провайдеры перевода')
            ->assertSee('Очередь переводов')
            ->assertSee('bg-accent text-white', false)
            ->assertSee('Статус')
            ->assertSee('Что переводится');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.settings.language.translate-queue'))
            ->assertRedirectContains('/admin/login');
    }

    public function test_queue_table_shows_translation_job_and_filters(): void
    {
        $this->actingAdmin();
        $reply = AutoReply::create([
            'type' => AutoReply::TYPE_WELCOME,
            'trigger' => '__system_welcome__',
            'response' => 'Добрый день!',
        ]);

        TranslationJob::create([
            'job_type' => TranslationJob::TYPE_AUTO_REPLY,
            'subject_type' => AutoReply::class,
            'subject_id' => $reply->id,
            'subject_label' => 'Приветственное сообщение: __system_welcome__',
            'source_locale' => 'ru',
            'target_locale' => 'en',
            'provider' => 'yandex',
            'status' => TranslationJob::STATUS_DONE,
            'attempts' => 1,
            'characters' => 12,
            'queued_at' => now(),
            'started_at' => now(),
            'finished_at' => now(),
            'meta' => ['source_preview' => 'Добрый день!'],
        ]);

        Livewire::test(TranslationQueuePage::class)
            ->assertSee('Приветственное сообщение')
            ->assertSee('ru → en')
            ->assertSee('Yandex')
            ->set('statusFilter', TranslationJob::STATUS_FAILED)
            ->assertDontSee('Приветственное сообщение')
            ->set('statusFilter', TranslationJob::STATUS_DONE)
            ->assertSee('Приветственное сообщение')
            ->set('search', 'yandex')
            ->assertSee('Приветственное сообщение');
    }
}
