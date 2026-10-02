<?php
/**
 * Server-side pagination helper.
 *
 *   require_once 'pagination_helper.php';
 *   $pg = paginate($total_rows, 10);          // before the list query
 *   $sql .= " LIMIT {$pg['per_page']} OFFSET {$pg['offset']}";
 *   ...
 *   echo render_pagination($pg);              // after the list markup
 *
 * Controls are styled by .pager-* in assets/css/ui.css.
 */

if (!function_exists('paginate')) {
    /**
     * @param int    $total    Total row count
     * @param int    $per_page Rows per page
     * @param string $param    GET parameter name for the page number
     * @return array{total:int,page:int,pages:int,per_page:int,offset:int,param:string}
     */
    function paginate($total, $per_page = 10, $param = 'page') {
        $total = max(0, (int)$total);
        $page  = max(1, (int)($_GET[$param] ?? 1));
        $pages = max(1, (int)ceil($total / $per_page));
        if ($page > $pages) $page = $pages;
        return [
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => (int)$per_page,
            'offset'   => ($page - 1) * $per_page,
            'param'    => $param,
        ];
    }
}

if (!function_exists('render_pagination')) {
    /**
     * Render "Showing X-Y of N" plus Prev / page numbers / Next.
     * Existing GET params (search, status, ...) are preserved.
     * @param array $pg    Result of paginate()
     * @param array $extra Extra query params to force on every link
     */
    function render_pagination(array $pg, array $extra = []) {
        if ($pg['pages'] <= 1) {
            if ($pg['total'] > 0) {
                return '<nav class="pager" aria-label="Pagination">'
                     . '<span class="pager-info">Showing ' . $pg['total'] . ' of ' . $pg['total'] . '</span>'
                     . '</nav>';
            }
            return '';
        }

        $param = $pg['param'];
        $qs = $_GET;
        unset($qs[$param]);
        $qs = array_merge($qs, $extra);
        $base = htmlspecialchars(basename($_SERVER['PHP_SELF']), ENT_QUOTES, 'UTF-8');

        $link = function ($p) use ($qs, $param, $base) {
            return $base . '?' . htmlspecialchars(http_build_query(array_merge($qs, [$param => $p])), ENT_QUOTES, 'UTF-8');
        };

        $from = $pg['total'] ? $pg['offset'] + 1 : 0;
        $to   = min($pg['offset'] + $pg['per_page'], $pg['total']);

        // Windowed page numbers: first, last, current +/- 2
        $nums = [1, $pg['pages']];
        for ($i = $pg['page'] - 2; $i <= $pg['page'] + 2; $i++) {
            if ($i >= 1 && $i <= $pg['pages']) $nums[] = $i;
        }
        $nums = array_values(array_unique($nums));
        sort($nums);

        ob_start();
        ?>
        <nav class="pager" aria-label="Pagination">
            <span class="pager-info">Showing <?= $from ?>–<?= $to ?> of <?= $pg['total'] ?></span>
            <div class="pager-nav">
                <?php if ($pg['page'] > 1): ?>
                    <a class="pager-btn" href="<?= $link($pg['page'] - 1) ?>" rel="prev">&laquo; Prev</a>
                <?php else: ?>
                    <span class="pager-btn pg-disabled" aria-hidden="true">&laquo; Prev</span>
                <?php endif; ?>

                <?php $prev = 0; foreach ($nums as $n): ?>
                    <?php if ($prev && $n - $prev > 1): ?>
                        <span class="pager-ellipsis">&hellip;</span>
                    <?php endif; ?>
                    <?php if ($n === $pg['page']): ?>
                        <span class="pager-page pg-current" aria-current="page"><?= $n ?></span>
                    <?php else: ?>
                        <a class="pager-page" href="<?= $link($n) ?>"><?= $n ?></a>
                    <?php endif; ?>
                    <?php $prev = $n; endforeach; ?>

                <?php if ($pg['page'] < $pg['pages']): ?>
                    <a class="pager-btn" href="<?= $link($pg['page'] + 1) ?>" rel="next">Next &raquo;</a>
                <?php else: ?>
                    <span class="pager-btn pg-disabled" aria-hidden="true">Next &raquo;</span>
                <?php endif; ?>
            </div>
        </nav>
        <?php
        return ob_get_clean();
    }
}
