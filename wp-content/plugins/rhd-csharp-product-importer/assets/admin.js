jQuery(document).ready(function ($) {
	"use strict";

	const $form = $("#rhd-import-form");
	const $progressDiv = $("#rhd-import-progress");
	const $resultsDiv = $("#rhd-import-results");
	const $progressText = $("#progress-text");
	const $progressCounter = $("#progress-counter");
	const $progressBarContainer = $("#progress-bar-container");
	const $progressBar = $("#progress-bar");
	const $spinner = $progressDiv.find(".spinner");

	$form.on("submit", function (e) {
		e.preventDefault();

		const fileInput = $("#csv_file")[0];
		const file = fileInput.files[0];

		if (!file) {
			alert("Please select a CSV file to upload.");
			return;
		}

		// Show progress and reset state
		showProgress("Analyzing CSV file...", 0, 0);
		$resultsDiv.hide();

		const createBundles = $("#create_bundles").is(":checked");
		const updateExisting = $("#update_existing").is(":checked");

		// Step 1: Get CSV info and total count
		const formData = new FormData();
		formData.append("action", "rhd_get_csv_info");
		formData.append("csv_file", file);
		formData.append("nonce", rhdImporter.nonce);

		$.ajax({
			url: rhdImporter.ajaxurl,
			type: "POST",
			data: formData,
			processData: false,
			contentType: false,
			success: function (response) {
				if (response.success) {
					const totalRows = response.data.total_rows;
					const tempFile = response.data.temp_file;
					
					if (totalRows === 0) {
						showError("No products found in CSV file.");
						return;
					}

					// Start chunked processing
					processChunks(tempFile, totalRows, createBundles, updateExisting);
				} else {
					showError(response.data || "Failed to analyze CSV file.");
				}
			},
			error: function (xhr, status, error) {
				showError("Failed to upload CSV file: " + error);
			}
		});
	});

	function processChunks(tempFile, totalRows, createBundles, updateExisting) {
		let currentOffset = 0;
		const chunkSize = 50; // Process 50 products at a time
		let totalImported = 0;
		let totalUpdated = 0;
		let allErrors = [];

		function processNextChunk() {
			const remaining = totalRows - currentOffset;
			const currentChunkSize = Math.min(chunkSize, remaining);
			
			if (remaining <= 0) {
				// All products processed, now finalize
				finalizeImport(tempFile, createBundles, totalImported, totalUpdated, allErrors);
				return;
			}

			showProgress(
				`Importing products...`, 
				currentOffset, 
				totalRows
			);

			$.ajax({
				url: rhdImporter.ajaxurl,
				type: "POST",
				data: {
					action: "rhd_process_chunk",
					nonce: rhdImporter.nonce,
					temp_file: tempFile,
					offset: currentOffset,
					chunk_size: chunkSize,
					update_existing: updateExisting ? "1" : "0"
				},
				success: function(response) {
					if (response.success) {
						const data = response.data;
						currentOffset = data.processed;
						totalImported += data.results.products_imported;
						totalUpdated += data.results.products_updated;
						allErrors = allErrors.concat(data.results.errors);

						// Update progress
						showProgress(
							"Importing products...", 
							data.processed, 
							data.total
						);

						// Process next chunk
						setTimeout(processNextChunk, 100); // Small delay to prevent overwhelming server
					} else {
						showError("Chunk processing failed: " + (response.data || "Unknown error"));
					}
				},
				error: function(xhr, status, error) {
					showError("Import failed: " + error);
				}
			});
		}

		// Start processing
		processNextChunk();
	}

	function finalizeImport(tempFile, createBundles, totalImported, totalUpdated, allErrors) {
		showProgress("Finalizing import (setting up grouped products and bundles)...", 0, 0, true);

		$.ajax({
			url: rhdImporter.ajaxurl,
			type: "POST",
			data: {
				action: "rhd_finalize_import",
				nonce: rhdImporter.nonce,
				temp_file: tempFile,
				create_bundles: createBundles ? "1" : "0"
			},
			success: function(response) {
				$progressDiv.hide();
				
				if (response.success) {
					const results = response.data.results;
					let successMessage = "Import completed successfully!<br>";
					
					if (totalImported > 0) {
						successMessage += `<br>• ${totalImported} products imported`;
					}
					if (totalUpdated > 0) {
						successMessage += `<br>• ${totalUpdated} products updated`;
					}
					if (results.grouped_products_updated > 0) {
						successMessage += `<br>• ${results.grouped_products_updated} grouped products set up`;
					}
					if (results.bundles_created > 0) {
						successMessage += `<br>• ${results.bundles_created} bundles created`;
					}

					let errorSection = "";
					if (allErrors.length > 0 || results.errors.length > 0) {
						const combinedErrors = allErrors.concat(results.errors);
						errorSection = `<br><br><strong>Errors encountered:</strong><ul style="margin-top: 5px;">`;
						combinedErrors.slice(0, 10).forEach(function(error) { // Show max 10 errors
							errorSection += `<li style="color: #d63638;">${error}</li>`;
						});
						if (combinedErrors.length > 10) {
							errorSection += `<li style="color: #d63638;">... and ${combinedErrors.length - 10} more errors</li>`;
						}
						errorSection += "</ul>";
					}

					$resultsDiv.html(
						'<div class="notice notice-success"><p>' + 
						successMessage + errorSection +
						'</p></div>'
					).show();
				} else {
					showError("Finalization failed: " + (response.data || "Unknown error"));
				}
			},
			error: function(xhr, status, error) {
				showError("Finalization failed: " + error);
			}
		});
	}

	function showProgress(message, current, total, isIndeterminate = false) {
		$progressText.text(message);
		$progressDiv.show();
		$spinner.addClass("is-active");

		if (isIndeterminate || total === 0) {
			$progressCounter.text("");
			$progressBarContainer.hide();
		} else {
			const percentage = Math.round((current / total) * 100);
			$progressCounter.text(`${current}/${total} (${percentage}%)`);
			$progressBar.css("width", percentage + "%");
			$progressBarContainer.show();
		}
	}

	function showError(message) {
		$progressDiv.hide();
		$resultsDiv.html(
			'<div class="notice notice-error"><p><strong>Error:</strong> ' + 
			message + 
			'</p></div>'
		).show();
	}
});
