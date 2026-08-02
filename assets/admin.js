(function() {
	'use strict';

	function getNumber(value, fallback) {
		var parsed = parseInt(value, 10);
		return Number.isNaN(parsed) ? fallback : parsed;
	}

	function setHidden(element, hidden) {
		if (!element) {
			return;
		}

		element.hidden = hidden;
	}

	function setText(element, value) {
		if (element) {
			element.textContent = value;
		}
	}

	function formatNumber(value) {
		return new Intl.NumberFormat().format(value || 0);
	}

	function formatDuration(seconds) {
		var strings = window.yoohwMediaOptimizer && window.yoohwMediaOptimizer.strings ? window.yoohwMediaOptimizer.strings : {};
		var safeSeconds = Math.max(0, Math.round(seconds || 0));
		var minutes = Math.floor(safeSeconds / 60);
		var remainder = safeSeconds % 60;

		if (minutes <= 0) {
			return remainder + (strings.secondsShort || 's');
		}

		return minutes + (strings.minutesShort || 'm') + ' ' + remainder + (strings.secondsShort || 's');
	}

	function formatTemplate(template, values) {
		return values.reduce(function(result, value, index) {
			return result.replace('%' + (index + 1) + '$s', value);
		}, template);
	}

	document.addEventListener('DOMContentLoaded', function() {
		var config = window.yoohwMediaOptimizer;
		var form = document.querySelector('[data-yoohw-mo-batch-form]');

		if (!config || !form || !window.fetch || !window.FormData) {
			return;
		}

		var startButton = form.querySelector('[data-yoohw-mo-start]');
		var stopButton = form.querySelector('[data-yoohw-mo-stop]');
		var progress = form.querySelector('[data-yoohw-mo-progress]');
		var progressTitle = form.querySelector('[data-yoohw-mo-progress-title]');
		var progressPercent = form.querySelector('[data-yoohw-mo-progress-percent]');
		var progressBar = form.querySelector('[data-yoohw-mo-progressbar]');
		var progressFill = form.querySelector('[data-yoohw-mo-progress-fill]');
		var progressNote = form.querySelector('[data-yoohw-mo-progress-note]');
		var progressLog = form.querySelector('[data-yoohw-mo-progress-log]');
		var currentNode = form.querySelector('[data-yoohw-mo-current]');
		var elapsedNode = form.querySelector('[data-yoohw-mo-elapsed]');
		var etaNode = form.querySelector('[data-yoohw-mo-eta]');
		var rateNode = form.querySelector('[data-yoohw-mo-rate]');
		var deliveryButton = document.querySelector('[data-yoohw-mo-test-delivery]');
		var deliveryResult = document.querySelector('[data-yoohw-mo-delivery-result]');
		var statNodes = {};
		var running = false;
		var stopRequested = false;
		var limit = Math.max(1, Math.min(50, getNumber(config.batchSize, 8)));
		var offset = 0;
		var totalFound = 0;
		var processedTotal = 0;
		var displayedPercent = 0;
		var targetPercent = 0;
		var animationFrame = 0;
		var heartbeatTimer = 0;
		var clockTimer = 0;
		var startTime = 0;
		var totals = {
			processed: 0,
			created: 0,
			existing: 0,
			skipped: 0,
			originalOptimized: 0,
			originalSkipped: 0,
			failed: 0
		};

		function setDeliveryMessage(message, isError) {
			if (!deliveryResult) {
				return;
			}

			deliveryResult.innerHTML = '';

			var paragraph = document.createElement('p');
			paragraph.textContent = message;

			if (isError) {
				paragraph.className = 'is-error';
			}

			deliveryResult.appendChild(paragraph);
		}

		function formatHeadStatus(report, formatLabel) {
			if (!report) {
				return config.strings.notTested;
			}

			if (report.ok) {
				return config.strings.ok + ' ' + report.status + ' · ' + (report.contentType || config.strings.unknownContentType);
			}

			if (report.message) {
				return config.strings.unavailable + ' · ' + report.message;
			}

			return formatLabel + ' ' + config.strings.notDetected;
		}

		function renderDeliveryResult(data) {
			if (!deliveryResult) {
				return;
			}

			deliveryResult.innerHTML = '';

			var rows = [
				{
					label: config.strings.sampleLabel,
					value: data.sample && data.sample.source ? data.sample.source : ''
				},
				{
					label: config.strings.directAvifLabel,
					value: formatHeadStatus(data.directAvif, 'AVIF')
				},
				{
					label: config.strings.directWebpLabel,
					value: formatHeadStatus(data.directWebp, 'WebP')
				},
				{
					label: config.strings.avifSourceLabel,
					value: data.sample && data.sample.avif ? config.strings.pictureAvailable : config.strings.pictureUnavailable
				},
				{
					label: config.strings.webpSourceLabel,
					value: data.sample && data.sample.webp ? config.strings.pictureAvailable : config.strings.pictureUnavailable
				},
				{
					label: config.strings.htmlModeLabel,
					value: data.htmlModeEnabled ? config.strings.enabled : config.strings.generateOnly
				}
			];

			var list = document.createElement('dl');

			rows.forEach(function(row) {
				var term = document.createElement('dt');
				var description = document.createElement('dd');

				term.textContent = row.label;
				description.textContent = row.value;

				list.appendChild(term);
				list.appendChild(description);
			});

			deliveryResult.appendChild(list);
		}

		function requestDeliveryTest() {
			var data = new FormData();

			data.append('action', 'yoohw_mo_test_delivery');
			data.append('nonce', config.nonce);

			return fetch(config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: data
			}).then(function(response) {
				if (!response.ok) {
					throw new Error(response.status + ' ' + response.statusText);
				}

				return response.json();
			}).then(function(response) {
				if (!response || !response.success) {
					throw new Error(response && response.data && response.data.message ? response.data.message : config.strings.deliveryFailed);
				}

				return response.data;
			});
		}

		if (deliveryButton && deliveryResult) {
			deliveryButton.addEventListener('click', function() {
				deliveryButton.disabled = true;
				setDeliveryMessage(config.strings.testingDelivery, false);

				requestDeliveryTest().then(function(data) {
					renderDeliveryResult(data);
					deliveryButton.disabled = false;
				}).catch(function(error) {
					setDeliveryMessage(error.message || config.strings.deliveryFailed, true);
					deliveryButton.disabled = false;
				});
			});
		}

		form.querySelectorAll('[data-yoohw-mo-stat]').forEach(function(node) {
			statNodes[node.dataset.yoohwMoStat] = node;
		});

		function setButtonContent(button, iconClass, label) {
			if (!button) {
				return;
			}

			button.innerHTML = '';

			var icon = document.createElement('span');
			icon.className = 'dashicons ' + iconClass;
			icon.setAttribute('aria-hidden', 'true');

			button.appendChild(icon);
			button.appendChild(document.createTextNode(label));
		}

		function writeProgress(percent) {
			var roundedPercent = Math.round(Math.max(0, Math.min(100, percent || 0)));

			setText(progressPercent, roundedPercent + '%');

			if (progressFill) {
				progressFill.style.width = roundedPercent + '%';
			}

			if (progressBar) {
				progressBar.setAttribute('aria-valuenow', String(roundedPercent));
			}
		}

		function animateProgress() {
			var delta = targetPercent - displayedPercent;

			if (Math.abs(delta) < 0.12) {
				displayedPercent = targetPercent;
			} else {
				displayedPercent += delta * 0.16;
			}

			writeProgress(displayedPercent);

			if (running || Math.abs(targetPercent - displayedPercent) >= 0.12) {
				animationFrame = window.requestAnimationFrame(animateProgress);
			} else {
				animationFrame = 0;
			}
		}

		function startProgressAnimation() {
			if (!animationFrame) {
				animationFrame = window.requestAnimationFrame(animateProgress);
			}
		}

		function setProgress(percent, title) {
			targetPercent = Math.max(0, Math.min(100, percent || 0));
			setText(progressTitle, title);
			startProgressAnimation();
		}

		function setProgressState(state) {
			if (!progress) {
				return;
			}

			progress.classList.remove('is-running', 'is-paused', 'is-complete', 'is-error');

			if (state) {
				progress.classList.add('is-' + state);
			}
		}

		function setStats() {
			Object.keys(totals).forEach(function(key) {
				setText(statNodes[key], formatNumber(totals[key]));
			});
		}

		function updateTiming() {
			if (!startTime) {
				setText(elapsedNode, formatDuration(0));
				setText(etaNode, '-');
				setText(rateNode, '-');
				return;
			}

			var elapsed = (Date.now() - startTime) / 1000;
			var rate = elapsed > 0 ? processedTotal / elapsed : 0;
			var remaining = totalFound > processedTotal ? totalFound - processedTotal : 0;

			setText(elapsedNode, formatDuration(elapsed));
			setText(rateNode, rate > 0 ? formatNumber(Math.round(rate * 60 * 10) / 10) + config.strings.perMinute : '-');

			if (remaining > 0 && rate > 0.01) {
				setText(etaNode, formatDuration(remaining / rate));
			} else if (totalFound > 0 && remaining <= 0) {
				setText(etaNode, formatDuration(0));
			} else {
				setText(etaNode, config.strings.notAvailable || '-');
			}
		}

		function startClock() {
			window.clearInterval(clockTimer);
			clockTimer = window.setInterval(updateTiming, 1000);
			updateTiming();
		}

		function stopClock() {
			window.clearInterval(clockTimer);
			clockTimer = 0;
			updateTiming();
		}

		function startHeartbeat() {
			window.clearInterval(heartbeatTimer);

			heartbeatTimer = window.setInterval(function() {
				var ceiling;

				if (!running) {
					return;
				}

				if (totalFound > 0) {
					ceiling = Math.min(99, ((processedTotal + Math.max(1, limit * 0.8)) / totalFound) * 100);
				} else {
					ceiling = 12;
				}

				if (targetPercent < ceiling) {
					setProgress(Math.min(ceiling, targetPercent + 0.8), config.strings.running);
				}
			}, 700);
		}

		function stopHeartbeat() {
			window.clearInterval(heartbeatTimer);
			heartbeatTimer = 0;
		}

		function addLog(message, isError) {
			if (!progressLog) {
				return;
			}

			var item = document.createElement('li');
			item.textContent = message;

			if (isError) {
				item.className = 'is-error';
			}

			progressLog.prepend(item);

			while (progressLog.children.length > 5) {
				progressLog.removeChild(progressLog.lastElementChild);
			}
		}

		function resetState() {
			totals = {
				processed: 0,
				created: 0,
				existing: 0,
				skipped: 0,
				originalOptimized: 0,
				originalSkipped: 0,
				failed: 0
			};
			totalFound = 0;
			processedTotal = offset;
			displayedPercent = 0;
			targetPercent = 0;
			startTime = Date.now();
			setStats();
			writeProgress(0);
			setText(currentNode, config.strings.waiting);
			setText(elapsedNode, formatDuration(0));
			setText(etaNode, '-');
			setText(rateNode, '-');

			if (progressLog) {
				progressLog.innerHTML = '';
			}
		}

		function setRunningState(nextRunning) {
			running = nextRunning;

			if (startButton) {
				startButton.disabled = nextRunning;
				setButtonContent(startButton, nextRunning ? 'dashicons-update' : 'dashicons-controls-play', nextRunning ? config.strings.runningButton : config.strings.start);
			}

			setHidden(stopButton, !nextRunning);

			if (nextRunning) {
				setProgressState('running');
				startHeartbeat();
				startClock();
				return;
			}

			stopHeartbeat();
			stopClock();
		}

		function requestBatch() {
			var data = new FormData();
			var forceField = form.querySelector('[name="force"]');

			data.append('action', 'yoohw_mo_optimize_batch');
			data.append('nonce', config.nonce);
			data.append('limit', String(limit));
			data.append('offset', String(offset));

			if (forceField && forceField.checked) {
				data.append('force', '1');
			}

			return fetch(config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: data
			}).then(function(response) {
				if (!response.ok) {
					throw new Error(response.status + ' ' + response.statusText);
				}

				return response.json();
			}).then(function(response) {
				if (!response || !response.success) {
					throw new Error(response && response.data && response.data.message ? response.data.message : config.strings.failed);
				}

				return response.data;
			});
		}

		function updateOffsetField(value) {
			var offsetField = form.querySelector('[name="offset"]');

			if (offsetField) {
				offsetField.value = String(value);
			}
		}

		function finishRun(title, note, state) {
			setProgress('complete' === state ? 100 : targetPercent, title);
			setText(progressNote, note);
			setProgressState(state);
			setRunningState(false);

			if ('complete' === state) {
				updateOffsetField(0);
				offset = 0;
			}
		}

		function handleBatch(data) {
			totalFound = data.found || totalFound;
			processedTotal = data.processedTotal || processedTotal;
			totals.processed += data.processed || 0;
			totals.created += data.created || 0;
			totals.existing += data.existing || 0;
			totals.skipped += data.skipped || 0;
			totals.originalOptimized += data.originalOptimized || 0;
			totals.originalSkipped += data.originalSkipped || 0;
			totals.failed += data.failed || 0;
			totals.failed += data.originalFailed || 0;
			offset = data.nextOffset || offset;
			updateOffsetField(offset);

			setStats();
			updateTiming();
			setProgress(data.percent || 0, data.hasMore ? config.strings.running : config.strings.finishing);
			setText(
				currentNode,
					totalFound > 0 ?
					formatTemplate(config.strings.processedOf, [formatNumber(processedTotal), formatNumber(totalFound)]) :
					config.strings.empty
			);

			if ((data.processed || 0) > 0) {
				addLog(formatTemplate(config.strings.processedBatch, [
					formatNumber(data.processed || 0),
					formatNumber(processedTotal),
					formatNumber(totalFound)
				]));
			}

			if (0 === totalFound) {
				finishRun(config.strings.empty, config.strings.empty, 'complete');
				return;
			}

			if (data.hasMore && !stopRequested) {
				window.setTimeout(runNextBatch, 120);
				return;
			}

			if (stopRequested && data.hasMore) {
				finishRun(config.strings.paused, config.strings.paused, 'paused');
				return;
			}

			finishRun(config.strings.complete, config.strings.complete, 'complete');
		}

		function handleFailure(error) {
			var percent = totalFound > 0 ? (processedTotal / totalFound) * 100 : targetPercent;

			setProgress(percent, config.strings.failed);
			setText(progressNote, error.message || config.strings.failed);
			setText(currentNode, error.message || config.strings.failed);
			addLog(error.message || config.strings.failed, true);
			setProgressState('error');
			setRunningState(false);
		}

		function runNextBatch() {
			if (stopRequested) {
				finishRun(config.strings.paused, config.strings.paused, 'paused');
				return;
			}

			setText(currentNode, config.strings.currentBatch);
			requestBatch().then(handleBatch).catch(handleFailure);
		}

		form.addEventListener('submit', function(event) {
			var offsetField = form.querySelector('[name="offset"]');

			event.preventDefault();

			if (running) {
				return;
			}

			limit = Math.max(1, Math.min(50, getNumber(config.batchSize, 8)));
			offset = Math.max(0, getNumber(offsetField ? offsetField.value : '', 0));
			stopRequested = false;

			setHidden(progress, false);
			resetState();
			setProgress(2, config.strings.starting);
			setText(progressNote, config.strings.starting);
			setRunningState(true);
			runNextBatch();
		});

		if (stopButton) {
			stopButton.addEventListener('click', function() {
				stopRequested = true;
				setText(progressNote, config.strings.paused);
				setText(currentNode, config.strings.paused);
				setHidden(stopButton, true);
			});
		}
	});
})();
