<?php
$page_header = martfury_get_page_header();
?>
<div class="page-header text-center page-header-blog layout-1">
	<div class="container">
		<?php
		if ( in_array( 'title', $page_header ) ) {
			the_archive_title( '<h1 class="entry-title">', '</h1>' );
		}
		martfury_get_breadcrumbs();
		?>
	</div>
</div>