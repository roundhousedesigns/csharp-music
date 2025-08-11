/**
 * Frontend JavaScript for RHD C. Sharp Product Importer
 * 
 * Handles enhanced bundle functionality for sheet music products
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		
		// Initialize individual product functionality
		initIndividualProductFunctionality();
		
	});

	/**
	 * Initialize individual product functionality
	 */
	function initIndividualProductFunctionality() {
		// Handle individual product add to cart
		$(document).on('click', '.individual-product .add-to-cart', function(e) {
			e.preventDefault();
			
			const $button = $(this);
			const productId = $button.data('product-id');
			
			addIndividualProductToCart(productId, $button);
		});
	}

	/**
	 * Add individual product to cart
	 */
	function addIndividualProductToCart(productId, $button) {
		const originalText = $button.text();
		$button.text('Adding...').prop('disabled', true);
		
		$.ajax({
			url: wc_add_to_cart_params.ajax_url,
			type: 'POST',
			data: {
				action: 'woocommerce_ajax_add_to_cart',
				product_id: productId,
				quantity: 1
			},
			success: function(response) {
				if (response.success) {
					// Trigger cart update
					$(document.body).trigger('added_to_cart', [response.data.fragments, response.data.cart_hash, $button]);
					
					// Show success message
					showMessage('Product added to cart successfully!', 'success');
				} else {
					showMessage('Failed to add product to cart. Please try again.', 'error');
				}
			},
			error: function() {
				showMessage('Failed to add product to cart. Please try again.', 'error');
			},
			complete: function() {
				$button.text(originalText).prop('disabled', false);
			}
		});
	}

	/**
	 * Show message to user
	 */
	function showMessage(message, type) {
		// Remove existing messages
		$('.rhd-message').remove();
		
		// Create message element
		const $message = $('<div class="rhd-message rhd-message-' + type + '">' + message + '</div>');
		
		// Add styles
		$message.css({
			'position': 'fixed',
			'top': '20px',
			'right': '20px',
			'padding': '10px 20px',
			'border-radius': '4px',
			'color': 'white',
			'font-weight': 'bold',
			'z-index': '9999',
			'background-color': type === 'success' ? '#4CAF50' : '#f44336'
		});
		
		// Add to page
		$('body').append($message);
		
		// Remove after 3 seconds
		setTimeout(function() {
			$message.fadeOut(function() {
				$(this).remove();
			});
		}, 3000);
	}

})(jQuery); 