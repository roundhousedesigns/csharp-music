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

					// Start chunked processing - bundles are always created
					processChunks(tempFile, totalRows, updateExisting);
				} else {
					showError(response.data || "Failed to analyze CSV file.");
				}
			},
			error: function (xhr, status, error) {
				showError("Failed to upload CSV file: " + error);
			}
		});
	});

	function processChunks(tempFile, totalRows, updateExisting) {
		let currentOffset = 0;
		const chunkSize = 50; // Process 50 products at a time
		let totalImported = 0;
		let totalUpdated = 0;
		let allErrors = [];
		let allFileNotFoundErrors = [];

		function processNextChunk() {
			const remaining = totalRows - currentOffset;
			const currentChunkSize = Math.min(chunkSize, remaining);
			
			if (remaining <= 0) {
				// All products processed, now finalize with bundle creation
				finalizeImport(tempFile, totalImported, totalUpdated, allErrors, allFileNotFoundErrors);
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
						
						// Collect file not found errors
						if (data.results.file_not_found) {
							allFileNotFoundErrors = allFileNotFoundErrors.concat(data.results.file_not_found);
						}

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

	function finalizeImport(tempFile, totalImported, totalUpdated, allErrors, allFileNotFoundErrors) {
		showProgress("Preparing bundle creation...", 0, 0, true);

		$.ajax({
			url: rhdImporter.ajaxurl,
			type: "POST",
			data: {
				action: "rhd_finalize_import",
				nonce: rhdImporter.nonce,
				temp_file: tempFile
			},
			success: function(response) {
				if (response.success) {
					const data = response.data;
					
					if (data.total_bundles > 0) {
						// Start chunked bundle creation
						processBundles(data.bundle_data_file, data.total_bundles, totalImported, totalUpdated, allErrors, allFileNotFoundErrors);
					} else {
						// No bundles to create, show final results
						showFinalResults(totalImported, totalUpdated, 0, allErrors, allFileNotFoundErrors, []);
					}
				} else {
					showError("Preparation failed: " + (response.data || "Unknown error"));
				}
			},
			error: function(xhr, status, error) {
				showError("Something went wrong during preparation: " + error);
			}
		});
	}

	function processBundles(bundleDataFile, totalBundles, totalImported, totalUpdated, allErrors, allFileNotFoundErrors) {
		let currentOffset = 0;
		const chunkSize = 5; // Process 5 bundles at a time
		let totalBundlesCreated = 0;
		let allBundleErrors = [];
		let allBundleFileErrors = [];

		function processNextBundleChunk() {
			const remaining = totalBundles - currentOffset;
			
			if (remaining <= 0) {
				// All bundles processed, show final results
				const combinedFileErrors = allFileNotFoundErrors.concat(allBundleFileErrors);
				showFinalResults(totalImported, totalUpdated, totalBundlesCreated, allErrors, combinedFileErrors, allBundleErrors);
				return;
			}

			showProgress(
				`Creating bundles...`, 
				currentOffset, 
				totalBundles
			);

			$.ajax({
				url: rhdImporter.ajaxurl,
				type: "POST",
				data: {
					action: "rhd_process_bundles",
					nonce: rhdImporter.nonce,
					bundle_data_file: bundleDataFile,
					offset: currentOffset,
					chunk_size: chunkSize
				},
				success: function(response) {
					if (response.success) {
						const data = response.data;
						currentOffset = data.processed;
						totalBundlesCreated += data.results.bundles_created;
						allBundleErrors = allBundleErrors.concat(data.results.errors);
						
						// Collect file not found errors from bundle creation
						if (data.results.file_not_found) {
							allBundleFileErrors = allBundleFileErrors.concat(data.results.file_not_found);
						}

						// Update progress
						showProgress(
							"Creating bundles...", 
							data.processed, 
							data.total
						);

						if (data.is_complete) {
							// All bundles processed, show final results
							const combinedFileErrors = allFileNotFoundErrors.concat(allBundleFileErrors);
							showFinalResults(totalImported, totalUpdated, totalBundlesCreated, allErrors, combinedFileErrors, allBundleErrors);
						} else {
							// Process next chunk
							setTimeout(processNextBundleChunk, 500); // Small delay between bundle chunks
						}
					} else {
						showError("Bundle creation failed: " + (response.data || "Unknown error"));
					}
				},
				error: function(xhr, status, error) {
					showError("Bundle creation failed: " + error);
				}
			});
		}

		// Start bundle processing
		processNextBundleChunk();
	}

	function showFinalResults(totalImported, totalUpdated, totalBundlesCreated, allErrors, allFileNotFoundErrors, allBundleErrors) {
		$progressDiv.hide();
		
		let successMessage = "Import completed successfully!<br>";
		
		if (totalImported > 0) {
			successMessage += `<br>• ${totalImported} products imported`;
		}
		if (totalUpdated > 0) {
			successMessage += `<br>• ${totalUpdated} products updated`;
		}
		if (totalBundlesCreated > 0) {
			successMessage += `<br>• ${totalBundlesCreated} bundles created`;
		}

		let errorSection = "";
		
		// Combine all error types
		const combinedErrors = allErrors.concat(allBundleErrors);
		const combinedFileErrors = allFileNotFoundErrors;
		const totalErrorCount = combinedErrors.length + combinedFileErrors.length;
		
		if (totalErrorCount > 0) {
			errorSection = `<br><br><strong>Issues encountered:</strong>`;
			
			// Add file not found summary if there are file errors
			if (combinedFileErrors.length > 0) {
				// Count file errors by type
				let productCount = 0;
				let soundCount = 0;
				let imageCount = 0;
				let bundleCount = 0;
				
				combinedFileErrors.forEach(function(error) {
					if (error.includes('Type: product')) {
						productCount++;
					} else if (error.includes('Type: sound')) {
						soundCount++;
					} else if (error.includes('Type: image')) {
						imageCount++;
					} else if (error.includes('Type: bundle')) {
						bundleCount++;
					}
				});
				
				errorSection += `<div style="background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin: 10px 0; border-radius: 4px;">`;
				errorSection += `<strong>Missing Files Summary:</strong><br>`;
				errorSection += `${productCount} products not found | ${soundCount} sounds not found | ${imageCount} images not found`;
				if (bundleCount > 0) {
					errorSection += ` | ${bundleCount} bundle folders not found`;
				}
				errorSection += `<br><strong>Total not found: ${combinedFileErrors.length}</strong>`;
				errorSection += `</div>`;
			}
			
			errorSection += `<ul style="margin-top: 5px;">`;
			
			// Show regular errors first
			const maxRegularErrors = Math.min(combinedErrors.length, 10);
			for (let i = 0; i < maxRegularErrors; i++) {
				errorSection += `<li style="color: #d63638;">${combinedErrors[i]}</li>`;
			}
			
			// Show file not found errors
			const maxFileErrors = Math.min(combinedFileErrors.length, 10 - maxRegularErrors);
			for (let i = 0; i < maxFileErrors; i++) {
				errorSection += `<li style="color: #d63638;">${combinedFileErrors[i]}</li>`;
			}
			
			if (totalErrorCount > 10) {
				errorSection += `<li style="color: #d63638;">... and ${totalErrorCount - 10} more issues</li>`;
			}
			errorSection += "</ul>";
		}

		// Add note about ZIP files if bundles were created
		if (totalBundlesCreated > 0) {
			errorSection += `<br><div style="background-color: #d1ecf1; border: 1px solid #bee5eb; padding: 10px; margin: 10px 0; border-radius: 4px;">`;
			errorSection += `<strong>Note:</strong> Bundle ZIP files will be created automatically when customers first download them. This speeds up the import process.`;
			errorSection += `</div>`;
		}

		$resultsDiv.html(
			'<div class="notice notice-success"><p>' + 
			successMessage + errorSection +
			'</p></div>'
		).show();
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
