<?php
declare(strict_types=1);

const ADMIN_LIST_PAGE_SIZE = 10;

function admin_requested_page(mixed $value): int
{
    if (!is_scalar($value) || !preg_match('/^[1-9][0-9]*$/', (string) $value)) return 1;
    $page = filter_var((string) $value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $page === false ? 1 : $page;
}

/** @return array{page:int,pages:int,total:int,offset:int,from:int,to:int,per_page:int} */
function admin_page_state(int $total, int $requestedPage, int $perPage = ADMIN_LIST_PAGE_SIZE): array
{
    $perPage = max(1, $perPage);
    $total = max(0, $total);
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, $requestedPage), $pages);
    $offset = ($page - 1) * $perPage;
    return [
        'page' => $page,
        'pages' => $pages,
        'total' => $total,
        'offset' => $offset,
        'from' => $total === 0 ? 0 : $offset + 1,
        'to' => min($total, $offset + $perPage),
        'per_page' => $perPage,
    ];
}

function admin_page_url(string $script, array $filters, int $page): string
{
    return $script . '?' . http_build_query([...$filters, 'page' => $page], '', '&', PHP_QUERY_RFC3986);
}

function admin_redirect_clamped_page(string $script, array $filters, array $state): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || !array_key_exists('page', $_GET)) return;
    if (!is_scalar($_GET['page']) || (string) $_GET['page'] !== (string) $state['page']) {
        header('Location: ' . admin_page_url($script, $filters, $state['page']));
        exit;
    }
}

function admin_pagination_html(array $state, string $script, array $filters): string
{
    $page = $state['page'];
    $pages = $state['pages'];
    $links = [];
    $link = static function (int $number, string $label, bool $active = false) use ($script, $filters): string {
        $url = htmlspecialchars(admin_page_url($script, $filters, $number), ENT_QUOTES, 'UTF-8');
        $label = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        return '<a class="admin-page-link' . ($active ? ' active' : '') . '" href="' . $url . '"'
            . ($active ? ' aria-current="page"' : '') . ' aria-label="Page ' . $number . '">' . $label . '</a>';
    };
    $links[] = $page > 1 ? $link($page - 1, 'Previous') : '<span class="admin-page-link disabled" aria-disabled="true">Previous</span>';
    if ($state['total'] > 0) {
        $numbers = [1, $pages];
        for ($number = max(1, $page - 2); $number <= min($pages, $page + 2); $number++) $numbers[] = $number;
        $numbers = array_values(array_unique($numbers));
        sort($numbers, SORT_NUMERIC);
        $previous = 0;
        foreach ($numbers as $number) {
            if ($previous > 0 && $number > $previous + 1) $links[] = '<span class="admin-page-ellipsis" aria-hidden="true">…</span>';
            $links[] = $link($number, (string) $number, $number === $page);
            $previous = $number;
        }
    }
    $links[] = $page < $pages ? $link($page + 1, 'Next') : '<span class="admin-page-link disabled" aria-disabled="true">Next</span>';
    return '<div class="admin-list-pagination"><p class="admin-page-range">Showing ' . $state['from'] . '–' . $state['to'] . ' of ' . $state['total'] . '</p>'
        . '<nav class="admin-page-links" aria-label="List pages">' . implode('', $links) . '</nav></div>';
}
