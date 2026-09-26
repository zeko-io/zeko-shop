/**
 * Zeko Shop frontend scripts.
 */
(function ($) {
	'use strict';

	var shop = window.zekoShop || {};
	var i18n = shop.i18n || {};

	function notice(message, type) {
		type = type || 'success';
		var $el = $('<div class="zeko-shop-toast zeko-shop-toast-' + type + '">' + $('<div>').text(message).html() + '</div>');
		$('body').append($el);
		window.setTimeout(function () {
			$el.fadeOut(200, function () { $el.remove(); });
		}, 3500);
	}

	function post(action, data) {
		return $.post(
			shop.ajaxUrl,
			$.extend({}, data, {
				action: action,
				nonce: shop.nonce
			})
		);
	}

	// ── Cart count badges ──────────────────────────────────────────
	function setCartCount(count) {
		$('[data-zeko-cart-count]').text(count);
	}

	function refreshCartCount(count) {
		setCartCount(count);
		$(document).trigger('zeko:cart-updated', [count]);
	}

	// ── Header mini-cart: fragment refresh + toggle ───────────────
	function refreshMiniCart() {
		var $wrapper = $('.zeko-header-mini-cart');
		if (!$wrapper.length) {
			return;
		}
		post('zeko_shop_mini_cart_fragment', {}).done(function (res) {
			if (!res.success) {
				return;
			}
			$wrapper.find('.zeko-mini-cart-panel').html(res.data.html);
			setCartCount(res.data.count);
		});
	}

	function openMiniCart(duration) {
		var $wrapper = $('.zeko-header-mini-cart');
		if (!$wrapper.length) {
			return;
		}
		$wrapper.addClass('is-open');
		$wrapper.find('.zeko-mini-cart-toggle').attr('aria-expanded', 'true');
		if (duration) {
			window.clearTimeout(openMiniCart._timer);
			openMiniCart._timer = window.setTimeout(closeMiniCart, duration);
		}
	}

	function closeMiniCart() {
		var $wrapper = $('.zeko-header-mini-cart');
		$wrapper.removeClass('is-open');
		$wrapper.find('.zeko-mini-cart-toggle').attr('aria-expanded', 'false');
	}

	$('body').on('click', '.zeko-mini-cart-toggle', function () {
		var $wrapper = $(this).closest('.zeko-header-mini-cart');
		if ($wrapper.hasClass('is-open')) {
			closeMiniCart();
		} else {
			openMiniCart(4000);
		}
	});

	$('body').on('click', '.zeko-header-mini-cart a', closeMiniCart);

	$(document).on('click', function (e) {
		if (!$(e.target).closest('.zeko-header-mini-cart').length) {
			closeMiniCart();
		}
	});

	$(document).on('keyup', function (e) {
		if (e.key === 'Escape' || e.keyCode === 27) {
			closeMiniCart();
		}
	});

	// Re-render the mini-cart panel whenever the cart changes.
	$(document).on('zeko:cart-updated', refreshMiniCart);

	// ── Add to cart ────────────────────────────────────────────────
	$('body').on('click', '.zeko-shop-add-to-cart', function () {
		var $btn = $(this);
		$btn.prop('disabled', true);
		post('zeko_shop_add_to_cart', {
			product_id: $btn.data('product-id'),
			qty: $btn.data('qty') || 1
		}).done(function (res) {
			if (res.success) {
				notice(res.data.message || i18n.addedToCart);
				refreshCartCount(res.data.count);
				openMiniCart(3000);
			} else {
				notice(res.data.message || i18n.genericError, 'error');
			}
		}).fail(function () {
			notice(i18n.genericError, 'error');
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	// ── Update cart quantity ──────────────────────────────────────
	$('body').on('change', '.zeko-shop-qty', function () {
		var $input = $(this);
		var productId = $input.data('product-id');
		post('zeko_shop_update_cart', {
			product_id: productId,
			qty: parseInt($input.val(), 10) || 0
		}).done(function (res) {
			if (res.success) {
				refreshCartCount(res.data.count);
				window.location.reload();
			} else {
				notice(res.data.message || i18n.genericError, 'error');
			}
		}).fail(function () {
			notice(i18n.genericError, 'error');
		});
	});

	// ── Remove from cart ──────────────────────────────────────────
	$('body').on('click', '.zeko-shop-remove', function () {
		var $btn = $(this);
		var productId = $btn.data('product-id');
		if (!window.confirm(i18n.confirmRemove)) {
			return;
		}
		post('zeko_shop_remove_from_cart', {
			product_id: productId
		}).done(function (res) {
			if (res.success) {
				window.location.reload();
			} else {
				notice(res.data.message || i18n.genericError, 'error');
			}
		}).fail(function () {
			notice(i18n.genericError, 'error');
		});
	});

	// ── Apply promo code ──────────────────────────────────────────
	$('body').on('click', '#zeko-shop-promo-apply', function () {
		var $btn = $(this);
		var $input = $('#zeko-shop-promo-code');
		var code = $.trim($input.val());
		if (!code) {
			notice(i18n.enterPromo, 'error');
			return;
		}
		$btn.prop('disabled', true);
		post('zeko_shop_validate_promo', {
			code: code
		}).done(function (res) {
			if (res.success) {
				window.location.reload();
			} else {
				notice(res.data.message || i18n.genericError, 'error');
			}
		}).fail(function () {
			notice(i18n.genericError, 'error');
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	// ── Remove promo code ────────────────────────────────────────
	$('body').on('click', '.zeko-shop-promo-remove', function () {
		post('zeko_shop_remove_promo', {}).done(function (res) {
			if (res.success) {
				window.location.reload();
			} else {
				notice(res.data.message || i18n.genericError, 'error');
			}
		}).fail(function () {
			notice(i18n.genericError, 'error');
		});
	});

	// ── Place order ───────────────────────────────────────────────
	$('#zeko-shop-place-order').on('click', function () {
		var $btn = $(this);
		$btn.prop('disabled', true).text('Processing…');
		post('zeko_shop_checkout', {})
			.done(function (res) {
				if (res.success && res.data.redirect) {
					window.location.href = res.data.redirect;
				} else {
					$btn.prop('disabled', false).text(i18n.orderPlaced);
					notice(res.data.message || i18n.genericError, 'error');
				}
			})
			.fail(function (xhr) {
				var message = i18n.genericError;
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					message = xhr.responseJSON.data.message;
				}
				$btn.prop('disabled', false).text('Place Order & Pay');
				notice(message, 'error');
			});
	});

	// ── Download invoice ──────────────────────────────────────────
	$('body').on('click', '.zeko-shop-download-invoice', function () {
		var $btn = $(this);
		$btn.prop('disabled', true);
		post('zeko_shop_download_invoice', {
			order_id: $btn.data('order-id')
		}).done(function (res) {
			if (!res.success) {
				notice(res.data.message || i18n.genericError, 'error');
				return;
			}
			var blob = new Blob([res.data.html], { type: 'text/html;charset=utf-8' });
			var url = window.URL.createObjectURL(blob);
			var $a = $('<a>').attr({
				href: url,
				download: res.data.filename
			}).appendTo('body');
			$a[0].click();
			$a.remove();
			window.URL.revokeObjectURL(url);
		}).fail(function () {
			notice(i18n.genericError, 'error');
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});
})(jQuery);
