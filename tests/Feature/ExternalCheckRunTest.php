<?php

use App\Jobs\VerifyBibliographyEntry;
use App\Models\CheckHistory;
use App\Models\ExternalCheckEntry;
use App\Models\ExternalCheckRun;
use App\Models\User;
use App\Services\Bibliography\ExternalCheckService;
use App\Services\Bibliography\SourceVerificationService;
use App\Support\BibEditorViewData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    Queue::fake();
    config(['bibliography.execution' => 'queue', 'queue.default' => 'database', 'services.openalex.key' => null,
        'bibliography.providers.crossref.interval' => 0, 'bibliography.providers.datacite.interval' => 0]);
});

function queuedBib(int $count = 1): string
{
    $text = '';
    for ($i = 0; $i < $count; $i++) {
        $text .= "@article{entry{$i}, title={Example Article}, author={Smith, John}, year={2020}, doi={10.1234/example{$i}}}\n";
    }

    return $text;
}

function createRun(User $user, int $count = 1): ExternalCheckRun
{
    return app(ExternalCheckService::class)->start($user, queuedBib($count), 'test.bib');
}

function runEntry(ExternalCheckEntry $entry): void
{
    (new VerifyBibliographyEntry($entry->id, now()->addMinutes(30)->toIso8601String()))
        ->handle(app(SourceVerificationService::class), app(ExternalCheckService::class));
}

function fakeQueuedWorks(): void
{
    Http::fake(['api.crossref.org/works/*' => function ($request) {
        $doi = rawurldecode(substr(parse_url($request->url(), PHP_URL_PATH), strlen('/works/')));

        return Http::response(['message' => ['DOI' => $doi, 'title' => ['Example Article'], 'author' => [['family' => 'Smith', 'given' => 'John']], 'published' => ['date-parts' => [[2020]]]]]);
    }]);
}

test('guest starts a queued run without external keys and repeated submit reuses it', function () {
    $user = User::factory()->create(['is_guest' => true, 'guest_expires_at' => now()->addHour(), 'role' => 'guest', 'openalex_api_key' => null]);
    $first = $this->actingAs($user)->postJson('/bibliography/external-checks', ['bib_content' => queuedBib(), 'original_filename' => 'test.bib'])->assertStatus(202);
    $second = $this->postJson('/bibliography/external-checks', ['bib_content' => queuedBib(), 'original_filename' => 'test.bib'])->assertStatus(202);
    expect($first->json('data.id'))->toBe($second->json('data.id'));
    $this->assertDatabaseCount('external_check_runs', 1);
    $this->assertDatabaseCount('external_check_entries', 1);
    Http::assertNothingSent();
    Queue::assertPushed(VerifyBibliographyEntry::class, 1);
});

test('run reading and cancellation require the owner including guest ownership', function () {
    $owner = User::factory()->create();
    $run = createRun($owner);
    $this->getJson('/bibliography/external-checks/'.$run->id)->assertUnauthorized();
    $other = User::factory()->create(['is_guest' => true, 'guest_expires_at' => now()->addHour()]);
    $this->actingAs($other)->getJson('/bibliography/external-checks/'.$run->id)->assertNotFound();
    $this->postJson('/bibliography/external-checks/'.$run->id.'/cancel')->assertNotFound();
    $this->actingAs($owner)->getJson('/bibliography/external-checks/'.$run->id)->assertOk();
});

test('entry retries count completion once and history receives the immutable snapshot', function () {
    $user = User::factory()->create();
    $run = createRun($user, 2);
    fakeQueuedWorks();
    $entries = $run->entries()->get();
    runEntry($entries[0]);
    runEntry($entries[0]);
    expect($run->fresh()->finished_entries)->toBe(1)->and($run->fresh()->status)->toBe('running');
    runEntry($entries[1]);
    $run = $run->fresh();
    expect($run->status)->toBe('completed')->and($run->finished_entries)->toBe(2);
    $history = CheckHistory::find($run->check_history_id);
    expect($history->status)->toBe('completed')->and($history->analysis_data['schema_version'])->toBe(2)
        ->and($history->analysis_data['raw_content'])->toBe(queuedBib(2))
        ->and($history->stats['verified_count'])->toBe(2);
    Http::assertSentCount(2);
});

test('cancellation deletion and duplicate jobs cannot resurrect or overwrite a run', function () {
    $run = createRun(User::factory()->create());
    $entry = $run->entries()->first();
    app(ExternalCheckService::class)->cancel($run);
    runEntry($entry);
    app(ExternalCheckService::class)->finish($entry->id, ['status' => 'verified']);
    expect($run->fresh()->status)->toBe('cancelled')->and($run->fresh()->finished_entries)->toBe(0);
    Http::assertNothingSent();
    $run->delete();
    runEntry($entry);
    $this->assertDatabaseCount('external_check_entries', 0);
});

test('expired or failed job records incomplete rather than leaving progress stuck', function () {
    $run = createRun(User::factory()->create());
    $entry = $run->entries()->first();
    $job = new VerifyBibliographyEntry($entry->id, now()->addMinutes(30)->toIso8601String());
    $job->failed(new RuntimeException('worker failure'));
    expect($run->fresh()->status)->toBe('completed')->and($run->fresh()->finished_entries)->toBe(1)
        ->and($entry->fresh()->result['status'])->toBe('incomplete');
    $job->failed(new RuntimeException('duplicate failure'));
    expect($run->fresh()->finished_entries)->toBe(1);
});

test('snapshot content hash keeps separate edits separate and enforces active run cap', function () {
    $user = User::factory()->create();
    $first = createRun($user);
    $second = createRun($user, 2);
    expect($first->content_hash)->not->toBe($second->content_hash);
    $this->actingAs($user)->postJson('/bibliography/external-checks', ['bib_content' => queuedBib(3), 'original_filename' => 'third.bib'])->assertUnprocessable();
});

test('duplicate bib keys and too many entries are rejected without losing records', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->postJson('/bibliography/external-checks', ['bib_content' => queuedBib().queuedBib(), 'original_filename' => 'test.bib'])->assertUnprocessable();
    $this->postJson('/bibliography/external-checks', ['bib_content' => queuedBib(101), 'original_filename' => 'test.bib'])->assertUnprocessable();
    $this->assertDatabaseCount('external_check_runs', 0);
    Http::assertNothingSent();
});

test('concurrent duplicate execution is deferred before any network call', function () {
    $run = createRun(User::factory()->create());
    $entry = $run->entries()->first();
    $lock = Cache::lock('bib:entry:'.$entry->id, 120);
    $lock->get();
    runEntry($entry);
    $lock->release();
    Http::assertNothingSent();
    expect($run->fresh()->finished_entries)->toBe(0);
});

test('synchronous compatibility API explicitly rejects a large file and accepts doi without title', function () {
    $this->postJson('/api/bib/check-external', ['text' => queuedBib(4)])->assertUnprocessable();
    Http::assertNothingSent();
    fakeQueuedWorks();
    $this->postJson('/api/sources/verify', ['fields' => ['doi' => '10.1234/example0']])->assertOk()->assertJsonPath('data.api_check.status', 'verified');
});

test('fifty entries are queued without traffic and finish with one request per identifier', function () {
    $run = createRun(User::factory()->create(), 50);
    Http::assertNothingSent();
    fakeQueuedWorks();
    foreach ($run->entries()->get() as $entry) {
        runEntry($entry);
    }
    expect($run->fresh()->finished_entries)->toBe(50)->and($run->fresh()->status)->toBe('completed');
    Http::assertSentCount(50);
});

test('legacy history is readable but not promoted to strict verified', function () {
    $user = User::factory()->create();
    $analysis = ['entries' => ['legacy' => ['type' => 'article', 'fields' => ['title' => 'Legacy'], 'api_check' => ['status' => 'found', 'found' => true]]]];
    $view = BibEditorViewData::make($analysis, $user);
    expect($view['apiFoundCount'])->toBe(0)->and($view['entries']['legacy']['api_check']['status'])->toBe('found');
});

test('transient retries share the six call budget across job executions', function () {
    $run = createRun(User::factory()->create());
    $entry = $run->entries()->first();
    Http::fake(['api.crossref.org/*' => Http::response([], 500), 'api.datacite.org/*' => Http::response([], 500)]);
    for ($i = 0; $i < 4; $i++) {
        Cache::forget('bib:next:crossref');
        Cache::forget('bib:next:datacite');
        runEntry($entry->fresh());
    }
    expect($entry->fresh()->request_count)->toBe(6)->and($entry->fresh()->result['status'])->toBe('incomplete')
        ->and($run->fresh()->finished_entries)->toBe(1);
    Http::assertSentCount(6);
});

test('expired guests cannot access or submit checks', function () {
    $user = User::factory()->create(['is_guest' => true, 'guest_expires_at' => now()->subMinute()]);
    $this->actingAs($user)->postJson('/bibliography/external-checks', ['bib_content' => queuedBib(), 'original_filename' => 'test.bib'])->assertUnauthorized();
});

test('pruning finishes abandoned entries and keeps saved history', function () {
    $run = createRun(User::factory()->create());
    $run->update(['created_at' => now()->subMinutes(36)]);
    $this->artisan('bibliography:prune')->assertSuccessful();
    expect($run->fresh()->status)->toBe('completed')->and($run->entries()->first()->result['status'])->toBe('incomplete');
    $run->update(['finished_at' => now()->subDays(31)]);
    $this->artisan('bibliography:prune')->assertSuccessful();
    expect(ExternalCheckRun::find($run->id))->toBeNull()->and(CheckHistory::find($run->check_history_id))->not->toBeNull();
});

test('a crashed request consumes its durable budget reservation before the next job execution', function () {
    $run = createRun(User::factory()->create());
    $entry = $run->entries()->first();
    $crash = true;
    Http::fake(['api.crossref.org/*' => function () use (&$crash) {
        if ($crash) {
            $crash = false;
            throw new RuntimeException('Simulated interruption after request reservation');
        }

        return Http::response(['message' => ['DOI' => '10.1234/example0', 'title' => ['Example Article'],
            'author' => [['family' => 'Smith', 'given' => 'John']], 'published' => ['date-parts' => [[2020]]]]]);
    }]);
    expect(fn () => runEntry($entry))->toThrow(RuntimeException::class);
    expect($entry->fresh()->request_count)->toBe(1)->and($run->fresh()->finished_entries)->toBe(0);
    runEntry($entry->fresh());
    expect($entry->fresh()->request_count)->toBe(2)->and($run->fresh()->finished_entries)->toBe(1);
});


test('synchronous editor check completes and saves history without dispatching jobs', function () {
    config(['bibliography.execution' => 'sync', 'queue.default' => 'sync']);
    fakeQueuedWorks();
    $response = $this->actingAs(User::factory()->create())
        ->postJson('/bibliography/external-checks', ['bib_content' => queuedBib(2), 'original_filename' => 'test.bib'])
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.finished_entries', 2)
        ->assertJsonPath('data.analysis.entries.entry0.api_check.status', 'verified');
    Queue::assertNothingPushed();
    Http::assertSentCount(2);
    $run = ExternalCheckRun::find($response->json('data.id'));
    expect(CheckHistory::find($run->check_history_id)->status)->toBe('completed');
    expect($run->entries()->sum('request_count'))->toBe(2);
});

test('synchronous editor check limits size before creating a run', function () {
    config(['bibliography.execution' => 'sync']);
    $this->actingAs(User::factory()->create())
        ->postJson('/bibliography/external-checks', ['bib_content' => queuedBib(4), 'original_filename' => 'test.bib'])
        ->assertUnprocessable();
    $this->assertDatabaseCount('external_check_runs', 0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});
