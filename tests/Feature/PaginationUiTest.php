<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Tests\TestCase;

class PaginationUiTest extends TestCase
{
    public function test_full_pagination_has_one_set_of_localized_accessible_controls_and_preserves_filters(): void
    {
        $paginator = new LengthAwarePaginator(range(1, 30), 424, 30, 1, ['path' => '/assets']);
        $paginator->appends(['q' => 'Усилитель & микшер', 'building_id' => 'unassigned']);
        $html = $paginator->links()->toHtml();
        $xpath = $this->xpath($html);

        $this->assertSame(1, $xpath->query('//nav[@aria-label="Страницы списка"]')->length);
        $this->assertSame(1, $xpath->query('//a[@rel="next"]')->length);
        $this->assertSame(0, $xpath->query('//a[@rel="prev"]')->length);
        $this->assertSame(1, $xpath->query('//span[@aria-disabled="true"]')->length);
        $this->assertSame(1, $xpath->query('//span[@aria-current="page"]')->length);
        $this->assertSame('1', $xpath->query('//span[@aria-current="page"]')->item(0)->textContent);
        $this->assertStringContainsString('Показано', $html);
        $this->assertStringContainsString('1–30', $html);
        $this->assertStringNotContainsString('Previous', $html);
        $this->assertStringNotContainsString('Showing', $html);
        $this->assertStringNotContainsString('page-item', $html);
        $this->assertStringNotContainsString('d-sm-none', $html);
        parse_str(parse_url($xpath->query('//a[@rel="next"]')->item(0)->getAttribute('href'), PHP_URL_QUERY), $query);
        $this->assertSame(['q' => 'Усилитель & микшер', 'building_id' => 'unassigned', 'page' => '2'], $query);
    }

    public function test_middle_and_last_pages_keep_compact_numbers_and_correct_navigation_boundaries(): void
    {
        foreach ([8, 15] as $page) {
            $paginator = new LengthAwarePaginator(range(1, $page === 15 ? 4 : 30), 424, 30, $page, ['path' => '/assets']);
            $html = $paginator->links()->toHtml();
            $xpath = $this->xpath($html);
            $this->assertLessThanOrEqual(9, $xpath->query('//li[@class="pagination-number"]')->length);
            $this->assertSame((string) $page, $xpath->query('//span[@aria-current="page"]')->item(0)->textContent);
            $this->assertSame(1, $xpath->query('//a[@rel="prev"]')->length);
            $this->assertSame($page === 15 ? 0 : 1, $xpath->query('//a[@rel="next"]')->length);
            $this->assertStringContainsString($page.' / 15', $html);
        }
    }

    public function test_single_page_is_not_rendered_and_simple_pagination_uses_the_same_controls(): void
    {
        $this->assertSame('', trim((new LengthAwarePaginator([1], 1, 30))->links()->toHtml()));
        $simple = new Paginator(range(1, 31), 30, 1, ['path' => '/history']);
        $html = $simple->links()->toHtml();
        $xpath = $this->xpath($html);
        $this->assertSame(1, $xpath->query('//nav[@aria-label="Страницы списка"]')->length);
        $this->assertSame(1, $xpath->query('//a[@rel="next"]')->length);
        $this->assertStringContainsString('Страница', $html);
        $this->assertStringNotContainsString('pagination-number', $html);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NONET);

        return new DOMXPath($document);
    }
}
