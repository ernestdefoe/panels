<?php

namespace ErnestDefoe\Panels\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Panels\Media\Store;
use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use PHPUnit\Framework\Attributes\Test;

class PanelsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const MEMBER = 2;
    private const ADULT = 3;
    private const MOD = 4;
    private const CREATOR = 5;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-panels');

        $now = Carbon::now();
        $past = $now->copy()->subDay();
        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'adult', 'email' => 'adult@machine.local', 'is_email_confirmed' => 1, 'panels_adult_at' => $past],
                ['id' => 4, 'username' => 'mod', 'email' => 'mod@machine.local', 'is_email_confirmed' => 1],
                ['id' => 5, 'username' => 'creator', 'email' => 'creator@machine.local', 'is_email_confirmed' => 1],
            ],
            'groups' => [
                ['id' => 10, 'name_singular' => 'Creator', 'name_plural' => 'Creators', 'is_hidden' => 0],
            ],
            'group_user' => [
                ['user_id' => 3, 'group_id' => Group::MEMBER_ID],
                ['user_id' => 4, 'group_id' => Group::MODERATOR_ID],
                ['user_id' => 5, 'group_id' => 10],
            ],
            'group_permission' => [
                ['group_id' => 10, 'permission' => 'panels.create'],
            ],
            'panel_series' => [
                $this->series(1, 'ink', 'all', ['cover_key' => 's/1/cover-aaaaaaaaaaaaaaaa-480.webp']),
                $this->series(2, 'bloom', 'mature', ['cover_key' => 's/2/cover-bbbbbbbbbbbbbbbb-480.webp']),
                $this->series(3, 'secret', 'explicit'),
                $this->series(4, 'mine', 'all', ['user_id' => self::CREATOR, 'chapter_count' => 0]),
            ],
            'panel_chapters' => [
                $this->chapter(1, 1, '1', ['storage' => 'public']),
                $this->chapter(2, 1, '2', ['published_at' => $now->copy()->addDay()]),
                $this->chapter(3, 2, '1'),
                $this->chapter(4, 3, '1'),
            ],
            'panel_pages' => [
                $this->page(1, 1, 's/1/c/1/aaaaaaaaaaaa-720.webp'),
                $this->page(2, 3, 's/2/c/3/dddddddddddd-720.webp'),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function series(int $id, string $slug, string $rating, array $over = []): array
    {
        return $over + [
            'id' => $id, 'slug' => $slug, 'title' => ucfirst($slug), 'format' => 'manga', 'reading_mode' => 'rtl',
            'status' => 'ongoing', 'content_rating' => $rating, 'user_id' => 1, 'is_hidden' => 0, 'is_featured' => 0, 'is_sold' => 0,
            'chapter_count' => 1, 'follower_count' => 0, 'rating_sum' => 0, 'rating_count' => 0, 'read_count' => 0,
            'latest_chapter_at' => Carbon::now()->subDay(), 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ];
    }

    /** @return array<string, mixed> */
    private function chapter(int $id, int $series, string $number, array $over = []): array
    {
        return $over + [
            'id' => $id, 'series_id' => $series, 'number' => $number, 'state' => 'published', 'published_at' => Carbon::now()->subDay(),
            'source' => 'upload', 'access' => 'free', 'storage' => 'private', 'page_count' => 1, 'user_id' => 1,
            'attested_at' => Carbon::now(), 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
            // Out already: its discussion was made and followers were told.
            'discussion_id' => ($over['published_at'] ?? null) ? null : 900 + $id, 'notified_at' => Carbon::now(),
        ];
    }

    /** @return array<string, mixed> */
    private function page(int $id, int $chapter, string $path): array
    {
        return ['id' => $id, 'chapter_id' => $chapter, 'position' => 1, 'segment' => 0, 'width' => 720, 'height' => 1040, 'bytes' => 10, 'color' => '#000000', 'variants' => json_encode(['720' => $path])];
    }

    /** @return array{int, array<string, mixed>|null, string} */
    private function call(string $method, string $path, ?int $as, array $body = []): array
    {
        $options = $as ? ['authenticatedAs' => $as] : [];
        if ($method !== 'GET') {
            $options['json'] = $body;
        }
        [$route, $query] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($query, $params);
        $response = $this->send($this->request($method, $route, $options)->withQueryParams($params)->withAttribute('bypassCsrfToken', true));
        $raw = (string) $response->getBody();
        if ($response->getStatusCode() >= 500) {
            fwrite(STDERR, "\n$method $path => ".substr((string) (json_decode($raw, true)['errors'][0]['detail'] ?? $raw), 0, 400)."\n");
        }

        return [$response->getStatusCode(), json_decode($raw, true), $raw];
    }

    #[Test]
    public function guests_see_open_and_mature_series_but_never_explicit(): void
    {
        [$status, $json] = $this->call('GET', '/api/panels/series', null);

        $this->assertSame(200, $status);
        $slugs = array_column($json['data'], 'slug');
        $this->assertContains('ink', $slugs);
        $this->assertContains('bloom', $slugs);
        $this->assertNotContains('secret', $slugs);
        $this->assertNotContains('mine', $slugs, 'nothing out yet: not in the library');

        [$status] = $this->call('GET', '/api/panels/series/secret', null);
        $this->assertSame(404, $status);
        [$status] = $this->call('GET', '/api/panels/series/secret/chapters/1', self::ADULT);
        $this->assertSame(404, $status, 'Explicit is off on this forum');
    }

    #[Test]
    public function a_mature_cover_is_only_ever_the_blurred_stand_in_for_those_who_may_not_read_it(): void
    {
        foreach ([null, self::MEMBER] as $viewer) {
            [, , $raw] = $this->call('GET', '/api/panels/series?filter[ids]=2', $viewer);
            $this->assertStringContainsString('cover-bbbbbbbbbbbbbbbb-blur', $raw);
            $this->assertStringNotContainsString('cover-bbbbbbbbbbbbbbbb-480', $raw);
            $this->assertStringNotContainsString('cover-bbbbbbbbbbbbbbbb-960', $raw);
        }
        [, , $raw] = $this->call('GET', '/api/panels/series?filter[ids]=2', self::ADULT);
        $this->assertStringContainsString('cover-bbbbbbbbbbbbbbbb-480', $raw);
    }

    #[Test]
    public function the_reader_sends_no_page_to_someone_who_may_not_read_it(): void
    {
        [, $json, $raw] = $this->call('GET', '/api/panels/series/bloom/chapters/1', null);
        $this->assertSame('signin', $json['refusal']);
        $this->assertSame([], $json['pages']);
        $this->assertStringNotContainsString('dddddddddddd', $raw);

        [, $json] = $this->call('GET', '/api/panels/series/bloom/chapters/1', self::MEMBER);
        $this->assertSame('age', $json['refusal']);
        $this->assertSame([], $json['pages']);

        $this->call('POST', '/api/panels/age', self::MEMBER);
        [, $json] = $this->call('GET', '/api/panels/series/bloom/chapters/1', self::MEMBER);
        $this->assertNull($json['refusal']);
        $this->assertStringContainsString('/panels/img/', $json['pages'][0]['sources']['720'], 'private chapter: a signed URL');
    }

    #[Test]
    public function a_scheduled_chapter_is_not_out_for_readers(): void
    {
        [, $json] = $this->call('GET', '/api/panels/series/ink', null);
        $this->assertSame(['1'], array_column($json['data']['chapters'], 'number'));

        [, $json] = $this->call('GET', '/api/panels/series/ink/chapters/2', null);
        $this->assertSame('unreleased', $json['refusal']);
        $this->assertSame([], $json['pages']);
    }

    #[Test]
    public function an_open_chapter_is_served_as_static_files(): void
    {
        [, $json] = $this->call('GET', '/api/panels/series/ink/chapters/1', null);

        $this->assertNull($json['refusal']);
        $this->assertStringContainsString('/assets/panels/s/1/c/1/aaaaaaaaaaaa-720.webp', $json['pages'][0]['sources']['720']);
        $this->assertNull($json['prev']);
        $this->assertNull($json['next'], 'chapter 2 is scheduled: not a neighbour yet');
    }

    #[Test]
    public function a_signed_page_url_works_only_for_its_member(): void
    {
        $this->app();
        resolve(Store::class)->put(false, 's/2/c/3/dddddddddddd-720.webp', 'RIFFxxxxWEBP');

        [, $json] = $this->call('GET', '/api/panels/series/bloom/chapters/1', self::ADULT);
        $url = $json['pages'][0]['sources']['720'];
        $path = parse_url($url, PHP_URL_PATH);

        $mine = $this->send($this->request('GET', $path, ['authenticatedAs' => self::ADULT]));
        $this->assertSame(200, $mine->getStatusCode());
        $this->assertSame('image/webp', $mine->getHeaderLine('Content-Type'));
        $this->assertSame('nosniff', $mine->getHeaderLine('X-Content-Type-Options'));

        $this->assertSame(403, $this->send($this->request('GET', $path, ['authenticatedAs' => self::MEMBER]))->getStatusCode());
        $this->assertSame(403, $this->send($this->request('GET', $path))->getStatusCode());
        $this->assertSame(403, $this->send($this->request('GET', $path.'x'))->getStatusCode(), 'a tampered token');
    }

    #[Test]
    public function the_library_costs_the_same_queries_for_one_series_or_twenty_four(): void
    {
        $this->app();
        $db = resolve(ConnectionInterface::class);
        for ($i = 10; $i < 33; $i++) {
            $db->table('panel_series')->insert($this->series($i, "s$i", 'all'));
            $db->table('panel_chapters')->insert($this->chapter($i + 100, $i, '1'));
            $db->table('panel_series')->where('id', $i)->update(['latest_chapter_id' => $i + 100]);
        }
        $db->table('panel_library')->insert(['user_id' => self::MEMBER, 'series_id' => 1, 'shelf' => 'reading', 'notify' => 1, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

        $count = function (string $path, int $as) use ($db): int {
            $db->flushQueryLog();
            $db->enableQueryLog();
            $this->call('GET', $path, $as);
            $n = count($db->getQueryLog());
            $db->disableQueryLog();

            return $n;
        };

        $count('/api/panels/series?limit=1', self::MEMBER);
        $one = $count('/api/panels/series?limit=1', self::MEMBER);
        $many = $count('/api/panels/series?limit=24', self::MEMBER);
        $this->assertSame($one, $many, "1 series: $one queries, 24: $many");
    }

    #[Test]
    public function a_member_chapter_waits_for_approval_and_a_published_one_gets_a_plain_discussion(): void
    {
        [$status, $json] = $this->call('POST', '/api/panels/chapters', self::CREATOR, ['seriesId' => 4, 'number' => '1', 'note' => 'Thanks for reading! <script>x</script>']);
        $this->assertSame(201, $status, json_encode($json));
        $id = $json['data']['id'];
        $this->app();
        resolve(ConnectionInterface::class)->table('panel_pages')->insert($this->page(50, $id, 's/4/c/'.$id.'/cccccccccccc-720.webp'));
        resolve(ConnectionInterface::class)->table('panel_chapters')->where('id', $id)->update(['page_count' => 1]);

        [$status, $json] = $this->call('PATCH', "/api/panels/chapters/$id", self::CREATOR, ['action' => 'publish']);
        $this->assertSame(422, $status, 'not attested');

        [, $json] = $this->call('PATCH', "/api/panels/chapters/$id", self::CREATOR, ['attest' => true, 'action' => 'publish']);
        $this->assertSame('pending', $json['data']['state']);
        $this->assertNull($json['data']['discussionId']);

        [, $json] = $this->call('PATCH', "/api/panels/chapters/$id", self::MOD, ['action' => 'approve']);
        $this->assertSame('published', $json['data']['state']);
        $discussion = $json['data']['discussionId'];
        $this->assertNotNull($discussion);

        [$status, $json, $raw] = $this->call('GET', "/api/discussions/$discussion?include=firstPost", null);
        $this->assertSame(200, $status);
        $this->assertStringStartsWith('Mine · ', $json['data']['attributes']['title']);
        $this->assertSame('1', $json['data']['attributes']['panelsChapter']['chapter']['number']);
        $this->assertStringNotContainsString('<script>', $raw);
        $this->assertStringNotContainsString('cccccccccccc', $raw, 'no page image in the discussion');
    }

    #[Test]
    public function a_takedown_removes_the_chapter_strikes_the_uploader_and_hides_its_discussion(): void
    {
        [$status, $json] = $this->call('POST', '/api/panels/takedowns', null, [
            'targetType' => 'chapter', 'targetId' => 1, 'name' => 'Rights Holder', 'email' => 'legal@example.com',
            'work' => 'Our original comic, page for page', 'urls' => "https://panels.test/read/ink/1", 'signature' => 'R. Holder',
            'goodFaith' => true, 'accurate' => true,
        ]);
        $this->assertSame(201, $status, json_encode($json));

        [$status] = $this->call('GET', '/api/panels/takedowns', self::MEMBER);
        $this->assertSame(403, $status);

        [, $json] = $this->call('PATCH', '/api/panels/takedowns/'.$json['data']['id'], self::MOD, ['action' => 'disable']);
        $this->assertSame('disabled', $json['data']['state']);

        [, $json] = $this->call('GET', '/api/panels/series/ink/chapters/1', null);
        $this->assertSame('removed', $json['refusal']);
        $this->assertSame([], $json['pages']);

        $this->app();
        $db = resolve(ConnectionInterface::class);
        $this->assertSame('private', $db->table('panel_chapters')->where('id', 1)->value('storage'));
        $this->assertSame(1, $db->table('panel_strikes')->where('user_id', 1)->count());
    }

    #[Test]
    public function a_claim_needs_both_statements(): void
    {
        [$status] = $this->call('POST', '/api/panels/takedowns', null, [
            'targetType' => 'chapter', 'targetId' => 1, 'name' => 'X', 'email' => 'x@example.com',
            'work' => 'Something of ours', 'urls' => 'https://x', 'signature' => 'X', 'goodFaith' => true,
        ]);
        $this->assertSame(422, $status);
    }

    #[Test]
    public function only_editors_and_staff_can_change_a_series(): void
    {
        [$status] = $this->call('PATCH', '/api/panels/series/1', self::MEMBER, ['title' => 'Hijacked']);
        $this->assertSame(403, $status);

        [$status] = $this->call('POST', '/api/panels/series', self::MEMBER, ['title' => 'Mine now']);
        $this->assertSame(403, $status, 'members need panels.create (moderators by default)');

        [$status, $json] = $this->call('POST', '/api/panels/series', self::CREATOR, ['title' => 'Library', 'format' => 'webtoon']);
        $this->assertSame(201, $status);
        $this->assertSame('library-series', $json['data']['slug'], 'a reserved word is never a slug');
        $this->assertSame('vertical', $json['data']['readingMode']);
    }
}
