<?php

namespace Tests\Unit\Models;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_fillable_fields(): void
    {
        $setting = new Setting();
        $fillable = $setting->getFillable();

        $this->assertContains('key', $fillable);
        $this->assertContains('value', $fillable);
        $this->assertContains('type', $fillable);
        $this->assertContains('is_secret', $fillable);
    }

    public function test_is_secret_is_cast_to_boolean(): void
    {
        $setting = Setting::create([
            'key' => 'test.bool_cast',
            'value' => 'hello',
            'type' => 'string',
            'is_secret' => false,
        ]);

        $this->assertFalse($setting->is_secret);
    }

    public function test_is_secret_true_stored_and_retrieved(): void
    {
        $setting = Setting::create([
            'key' => 'test.secret_flag',
            'value' => 'some-value',
            'type' => 'string',
            'is_secret' => true,
        ]);

        $fresh = Setting::find($setting->id);
        $this->assertNotNull($fresh);
        $this->assertTrue($fresh->is_secret);
    }

    public function test_value_is_stored_as_text_in_db(): void
    {
        $setting = Setting::create([
            'key' => 'test.plain',
            'value' => 'plain-value',
            'type' => 'string',
            'is_secret' => false,
        ]);

        $rawRow = \Illuminate\Support\Facades\DB::table('settings')
            ->where('id', $setting->id)
            ->value('value');

        $this->assertSame('plain-value', $rawRow);
    }

    public function test_type_column_is_stored_correctly(): void
    {
        foreach (['string', 'bool', 'int', 'json'] as $type) {
            $setting = Setting::create([
                'key' => 'test.type_' . $type,
                'value' => 'x',
                'type' => $type,
                'is_secret' => false,
            ]);

            $fresh = Setting::find($setting->id);
            $this->assertNotNull($fresh);
            $this->assertSame($type, $fresh->type, "type column should store '{$type}'");
        }
    }

    public function test_value_is_nullable(): void
    {
        $setting = Setting::create([
            'key' => 'test.nullable',
            'value' => null,
            'type' => 'string',
            'is_secret' => false,
        ]);

        $this->assertNull($setting->value);
    }

    public function test_created_at_is_cast_to_datetime(): void
    {
        $setting = Setting::create([
            'key' => 'test.datetime_cast',
            'value' => null,
            'type' => 'string',
            'is_secret' => false,
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $setting->created_at);
    }
}
