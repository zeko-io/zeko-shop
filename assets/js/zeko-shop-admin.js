/**
 * Zeko Shop admin scripts (manual order screen).
 */
(function ($) {
	'use strict';

	function lineTemplate() {
		var $first = $('#zeko-shop-order-lines-body .zeko-shop-order-line').first();
		if (!$first.length) {
			return null;
		}
		return $first.clone(true);
	}

	$('#zeko-shop-order-line-add').on('click', function () {
		var $row = lineTemplate();
		if (!$row) {
			return;
		}
		$row.find('select').val('');
		$row.find('input').val('1');
		$('#zeko-shop-order-lines-body').append($row);
	});

	$('body').on('click', '.zeko-shop-order-line-remove', function () {
		var $rows = $('#zeko-shop-order-lines-body .zeko-shop-order-line');
		if ($rows.length > 1) {
			$(this).closest('.zeko-shop-order-line').remove();
		} else {
			$(this).closest('.zeko-shop-order-line').find('select').val('');
			$(this).closest('.zeko-shop-order-line').find('input').val('1');
		}
	});
})(jQuery);
