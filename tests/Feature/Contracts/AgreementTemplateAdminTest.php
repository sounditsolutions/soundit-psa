<?php

namespace Tests\Feature\Contracts;

use App\Models\AgreementTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AgreementTemplateAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        config(['signing.enabled' => true]);
    }

    private function form(): array
    {
        return ['template_name' => 'Example template', 'template_kind' => 'agreement',
            'template_type' => 'managed', 'template_steps' => 2, 'template_countersign' => 1,
            'template_body' => '<p>DRAFT — Terms</p>{{lines_table}}'];
    }

    public function test_admin_can_create_edit_activate_and_deactivate_without_writing_held_fields(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('settings.agreement-templates.create'))->assertOk()->assertSee('Managed Services is two-step: proposal acceptance, then the agreement');
        $this->post(route('settings.agreement-templates.store'), $this->form() + ['apply_billing_on_sign' => 1, 'on_complete' => 'purchase_block'])->assertRedirect();
        $template = AgreementTemplate::sole();
        $this->assertFalse($template->apply_billing_on_sign);
        $this->assertSame('none', $template->on_complete);
        $this->assertFalse($template->is_active);
        $this->patch(route('settings.agreement-templates.update', $template), array_replace($this->form(), ['template_name' => 'Updated example']))->assertRedirect();
        $this->assertSame('Updated example', $template->fresh()->name);
        $this->post(route('settings.agreement-templates.activate', $template))->assertRedirect();
        $this->assertTrue($template->fresh()->is_active);
        $this->post(route('settings.agreement-templates.deactivate', $template))->assertRedirect();
        $this->assertFalse($template->fresh()->is_active);
        $this->get(route('settings.agreement-templates.index'))->assertOk()->assertSee('Updated example')->assertSee('Inactive');
        $this->assertDatabaseCount('signable_documents', 0);
    }

    public function test_missing_rate_card_is_refused_by_name_without_saving_and_keeps_input(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $url = route('settings.agreement-templates.create');
        $form = array_replace($this->form(), ['template_body' => '<p>DRAFT — No card</p>']);
        $this->from($url)->post(route('settings.agreement-templates.store'), $form)
            ->assertRedirect($url)->assertSessionHasErrors(['template_body' => 'signing_lines_table_missing']);
        $this->get($url)->assertSee('signing_lines_table_missing')->assertSee('DRAFT — No card');
        $this->assertDatabaseCount('agreement_templates', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_unknown_token_is_refused_by_name_without_saving_and_keeps_input(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $url = route('settings.agreement-templates.create');
        $form = array_replace($this->form(), ['template_body' => '{{unsupported}}{{lines_table}}']);
        $this->from($url)->post(route('settings.agreement-templates.store'), $form)
            ->assertRedirect($url)->assertSessionHasErrors(['template_body' => 'signing_unknown_merge_token']);
        $this->get($url)->assertSee('signing_unknown_merge_token')->assertSee('{{unsupported}}');
        $this->assertDatabaseCount('agreement_templates', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_form_refusals_keep_old_input_and_do_not_save(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $url = route('settings.agreement-templates.create');
        foreach ([
            ['template_kind', 'invalid', 'signing_template_kind_invalid'],
            ['template_type', 'invalid', 'signing_template_type_invalid'],
            ['template_steps', 3, 'signing_template_steps_invalid'],
            ['template_body', ' ', 'signing_template_body_empty'],
            ['template_kind', 'proposal', 'signing_template_proposal_invalid'],
        ] as [$field, $value, $label]) {
            $this->from($url)->post(route('settings.agreement-templates.store'), array_replace($this->form(), [$field => $value]))
                ->assertRedirect($url)->assertSessionHasErrors([$field => $label])->assertSessionHasInput('template_name', 'Example template');
            $this->get($url)->assertSee($label)->assertSee('Example template');
            $this->assertDatabaseCount('agreement_templates', 0);
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        foreach (app('router')->getRoutes() as $route) {
            if (str_starts_with($route->getName() ?? '', 'settings.agreement-templates.')) {
                $this->assertNotContains('DELETE', $route->methods());
            }
        }
    }

    public function test_model_save_guards_are_individually_named_and_defaults_allow_omitted_kind(): void
    {
        $valid = ['name' => 'Example', 'contract_type' => 'managed', 'body' => '{{lines_table}}'];
        $template = AgreementTemplate::create($valid);
        $this->assertSame('agreement', $template->kind);
        foreach ([
            ['kind', 'invalid', 'signing_template_kind_invalid'],
            ['contract_type', 'invalid', 'signing_template_type_invalid'],
            ['signing_steps', 3, 'signing_template_steps_invalid'],
            ['on_complete', 'invalid', 'signing_template_on_complete_invalid'],
            ['body', null, 'signing_template_body_empty'],
            ['body', ' ', 'signing_template_body_empty'],
            ['kind', 'proposal', 'signing_template_proposal_invalid'],
        ] as [$field, $value, $label]) {
            $before = $template->fresh()->getRawOriginal();
            try {
                $template->fresh()->update([$field => $value]);
                $this->fail('Invalid template saved: '.$field);
            } catch (\InvalidArgumentException $error) {
                $this->assertSame($label, $error->getMessage());
            }
            $this->assertSame($before, $template->fresh()->getRawOriginal());
        }
        $template->update(['kind' => 'proposal', 'requires_countersign' => false]);
        $this->assertSame('proposal', $template->fresh()->kind);
    }

    public function test_every_admin_route_is_off_when_disabled_and_tech_cannot_write(): void
    {
        $template = AgreementTemplate::create(['name' => 'Example', 'contract_type' => 'managed', 'body' => '{{lines_table}}']);
        $routes = [['get', 'index'], ['get', 'create'], ['post', 'store'], ['get', 'edit'], ['patch', 'update'], ['post', 'activate'], ['post', 'deactivate']];
        $this->get(route('settings.agreement-templates.index'))->assertRedirect();
        $this->actingAs(User::factory()->create(['role' => 'tech']));
        foreach ($routes as [$method, $name]) {
            $this->{$method}(route('settings.agreement-templates.'.$name, $template), $method === 'get' ? [] : $this->form())->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('settings.agreement-templates.index'))->assertOk()->assertSee('sidebar-label">Agreement templates', false);
        config(['signing.enabled' => false]);
        foreach ($routes as [$method, $name]) {
            $this->{$method}(route('settings.agreement-templates.'.$name, $template), $method === 'get' ? [] : $this->form())->assertNotFound();
        }
        $this->get(route('clients.index'))->assertOk()->assertDontSee('sidebar-label">Agreement templates', false);
        $this->assertDatabaseCount('agreement_templates', 1);
        $this->assertFalse($template->fresh()->is_active);
    }
}
