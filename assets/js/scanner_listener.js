/**
 * QID Management System - Real-Time Scanner Listener & Instant Modal
 * Connects desktop browser to user's private SSE stream /api/scan_stream.php
 */

(function() {
    'use strict';

    let eventSource = null;
    let fallbackPollTimer = null;
    let audioContext = null;
    let isConnected = false;

    // Pleasant two-tone chime via Web Audio API (Zero external MP3 dependencies)
    function playScanChime() {
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;
            if (!audioContext) {
                audioContext = new AudioCtx();
            }
            if (audioContext.state === 'suspended') {
                audioContext.resume();
            }

            const now = audioContext.currentTime;

            // Tone 1: High crisp ding (880Hz - A5)
            const osc1 = audioContext.createOscillator();
            const gain1 = audioContext.createGain();
            osc1.type = 'sine';
            osc1.frequency.setValueAtTime(880, now);
            gain1.gain.setValueAtTime(0.2, now);
            gain1.gain.exponentialRampToValueAtTime(0.001, now + 0.35);
            osc1.connect(gain1);
            gain1.connect(audioContext.destination);
            osc1.start(now);
            osc1.stop(now + 0.35);

            // Tone 2: Harmonic resolving tone (1318.5Hz - E6)
            const osc2 = audioContext.createOscillator();
            const gain2 = audioContext.createGain();
            osc2.type = 'sine';
            osc2.frequency.setValueAtTime(1318.5, now + 0.08);
            gain2.gain.setValueAtTime(0.25, now + 0.08);
            gain2.gain.exponentialRampToValueAtTime(0.001, now + 0.55);
            osc2.connect(gain2);
            gain2.connect(audioContext.destination);
            osc2.start(now + 0.08);
            osc2.stop(now + 0.55);
        } catch (e) {
            // Audio context blocked by autoplay policy until user clicks anywhere
        }
    }

    // Initialize Modal in DOM if not already present
    function ensureModalMarkup() {
        if (document.getElementById('scannerLiveModal')) return;

        const modalHtml = `
        <div class="modal fade" id="scannerLiveModal" tabindex="-1" aria-labelledby="scannerLiveModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content shadow-lg border-0" style="border-radius: 16px; overflow: hidden;">
                    <!-- Modal Header -->
                    <div class="modal-header text-white" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); border-bottom: 3px solid #8a1538;">
                        <div class="d-flex align-items-center gap-2">
                            <span class="p-2 rounded-circle bg-success text-white d-inline-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                                <i class="fa-solid fa-mobile-screen-button"></i>
                            </span>
                            <div>
                                <h6 class="modal-title fw-bold mb-0" id="scannerLiveModalLabel">QID Scanned from Mobile App</h6>
                                <span class="small text-white-50" id="scannerScanMeta">Real-Time Mobile Push</span>
                            </div>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <!-- Modal Body -->
                    <div class="modal-body p-4" id="scannerModalBody">
                        <!-- Injected dynamically -->
                    </div>

                    <!-- Modal Footer -->
                    <div class="modal-footer bg-light" id="scannerModalFooter">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHtml);
    }

    // Render Scanned Payload into Modal
    function showScanPopup(data) {
        ensureModalMarkup();
        playScanChime();

        const metaEl = document.getElementById('scannerScanMeta');
        const bodyEl = document.getElementById('scannerModalBody');
        const footerEl = document.getElementById('scannerModalFooter');

        const scannedTime = data.scanned_time || 'Just now';
        const scannerDevice = (data.scanned_by && data.scanned_by.device_name) ? data.scanned_by.device_name : 'Mobile App';
        const staffName = (data.scanned_by && data.scanned_by.full_name) ? data.scanned_by.full_name : 'Staff';

        if (metaEl) {
            metaEl.innerHTML = `<i class="fa-solid fa-bolt text-warning me-1"></i> Received at ${scannedTime} &bull; ${staffName} (${scannerDevice})`;
        }

        if (data.exists && data.record) {
            const r = data.record;
            const balanceClass = r.remaining_balance > 0 ? 'text-danger' : 'text-success';
            const statusBadge = r.expiry_status === 'Expired' 
                ? '<span class="badge bg-danger">EXPIRED</span>' 
                : (r.expiry_status === 'Expiring' ? '<span class="badge bg-warning text-dark">EXPIRING SOON</span>' : '<span class="badge bg-success">ACTIVE</span>');

            let paymentsHtml = '';
            if (data.payments && data.payments.length > 0) {
                paymentsHtml = `
                <div class="mt-3">
                    <h6 class="fw-bold small text-muted text-uppercase mb-2"><i class="fa-solid fa-receipt me-1"></i> Recent Installments</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-custom align-middle small mb-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Receipt</th>
                                    <th>Method</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${data.payments.map(p => `
                                    <tr>
                                        <td>${p.payment_date} <small class="text-muted">${p.payment_time ? p.payment_time.substring(0,5) : ''}</small></td>
                                        <td><span class="badge bg-light text-dark font-monospace">${p.receipt_no || '—'}</span></td>
                                        <td>${p.payment_method}</td>
                                        <td class="text-end fw-bold text-success">+ ${parseFloat(p.amount).toFixed(2)}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
                `;
            }

            bodyEl.innerHTML = `
            <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 pb-3 mb-3 border-bottom">
                <div>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle mb-1 font-monospace">
                        <i class="fa-solid fa-id-card me-1"></i> QID: ${r.qid_number}
                    </span>
                    <h4 class="fw-bold text-dark mb-1">${r.full_name}</h4>
                    <div class="text-muted small">
                        <span><i class="fa-solid fa-building me-1"></i> ${r.company_name || 'Individual'}</span>
                        <span class="mx-2">&bull;</span>
                        <span><i class="fa-solid fa-briefcase me-1"></i> ${r.job_title || 'N/A'}</span>
                        <span class="mx-2">&bull;</span>
                        <span><i class="fa-solid fa-earth-americas me-1"></i> ${r.nationality || 'N/A'}</span>
                    </div>
                </div>
                <div class="text-end">
                    ${statusBadge}
                    <div class="small text-muted mt-1">
                        Expiry: <strong>${r.expiry_formatted}</strong>
                    </div>
                </div>
            </div>

            <!-- Financial Metrics Grid -->
            <div class="row g-2 mb-3">
                <div class="col-4">
                    <div class="p-3 bg-light rounded text-center border">
                        <span class="small text-muted text-uppercase d-block" style="font-size: 0.72rem;">Agreed Charge</span>
                        <span class="fs-5 fw-bold text-dark">${r.charge_formatted}</span>
                    </div>
                </div>
                <div class="col-4">
                    <div class="p-3 bg-success-subtle rounded text-center border border-success-subtle">
                        <span class="small text-success-emphasis text-uppercase d-block" style="font-size: 0.72rem;">Total Collected</span>
                        <span class="fs-5 fw-bold text-success">${r.paid_formatted}</span>
                    </div>
                </div>
                <div class="col-4">
                    <div class="p-3 ${r.remaining_balance > 0 ? 'bg-danger-subtle border-danger-subtle' : 'bg-light'} rounded text-center border">
                        <span class="small ${r.remaining_balance > 0 ? 'text-danger-emphasis' : 'text-muted'} text-uppercase d-block" style="font-size: 0.72rem;">Remaining Due</span>
                        <span class="fs-5 fw-bold ${balanceClass}">${r.balance_formatted}</span>
                    </div>
                </div>
            </div>

            ${paymentsHtml}
            `;

            footerEl.innerHTML = `
                <a href="statement.php?id=${r.id}" target="_blank" class="btn btn-outline-secondary btn-sm">
                    <i class="fa-solid fa-print me-1"></i> Print Statement
                </a>
                <a href="record_detail.php?id=${r.id}" class="btn btn-primary btn-sm px-3 fw-semibold">
                    <i class="fa-solid fa-folder-open me-1"></i> Open Customer File
                </a>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            `;
        } else {
            // Unregistered Person Detected
            const qid = data.qid_number;
            bodyEl.innerHTML = `
            <div class="text-center py-4">
                <div class="p-3 bg-warning-subtle text-warning-emphasis rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 68px; height: 68px;">
                    <i class="fa-solid fa-user-plus fa-2x"></i>
                </div>
                <h5 class="fw-bold text-dark mb-1">New Qatar ID Scanned!</h5>
                <div class="font-monospace fs-4 fw-bold text-primary mb-2">${qid}</div>
                <p class="text-muted small mb-0" style="max-width: 420px; margin: 0 auto;">
                    This QID is not registered in your database yet. Click below to create a new person file with this QID pre-filled.
                </p>
            </div>
            `;

            const cardName = data.card_extracted?.name || '';
            const cardNat = data.card_extracted?.nationality || '';
            const cardJob = data.card_extracted?.job || '';
            const cardExp = data.card_extracted?.expiry || '';

            let params = `prefill_qid=${encodeURIComponent(qid)}`;
            if (cardName) params += `&prefill_name=${encodeURIComponent(cardName)}`;
            if (cardNat) params += `&prefill_nationality=${encodeURIComponent(cardNat)}`;
            if (cardJob) params += `&prefill_job=${encodeURIComponent(cardJob)}`;
            if (cardExp) params += `&prefill_expiry=${encodeURIComponent(cardExp)}`;

            footerEl.innerHTML = `
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Dismiss</button>
                <a href="record_add.php?${params}" class="btn btn-success btn-sm px-4 fw-bold">
                    <i class="fa-solid fa-plus me-1"></i> Register This Person Now
                </a>
            `;
        }

        // Show Bootstrap Modal
        const modalInstance = new bootstrap.Modal(document.getElementById('scannerLiveModal'));
        modalInstance.show();
    }

    // Update Topbar Status Pill
    function setIndicatorStatus(status) {
        const pill = document.getElementById('desktopScannerPill');
        if (!pill) return;

        if (status === 'connected') {
            pill.innerHTML = `<i class="fa-solid fa-circle text-success" style="font-size: 0.55rem; animation: pulse 1.8s infinite;"></i> <span class="d-none d-md-inline">Mobile Scanner Active</span>`;
            pill.className = 'badge bg-success-subtle text-success border border-success-subtle d-inline-flex align-items-center gap-1 py-1 px-2 text-decoration-none';
            pill.setAttribute('title', 'Ready to receive real-time scans from your phone app');
        } else if (status === 'connecting') {
            pill.innerHTML = `<i class="fa-solid fa-circle text-warning" style="font-size: 0.55rem;"></i> <span class="d-none d-md-inline">Connecting...</span>`;
            pill.className = 'badge bg-warning-subtle text-warning-emphasis border border-warning-subtle d-inline-flex align-items-center gap-1 py-1 px-2 text-decoration-none';
        } else {
            pill.innerHTML = `<i class="fa-solid fa-circle text-secondary" style="font-size: 0.55rem;"></i> <span class="d-none d-md-inline">Scanner Standby</span>`;
            pill.className = 'badge bg-light text-secondary border d-inline-flex align-items-center gap-1 py-1 px-2 text-decoration-none';
        }
    }

    // Start Server-Sent Events stream
    function initScannerStream() {
        if (!window.EventSource) {
            startPollingFallback();
            return;
        }

        setIndicatorStatus('connecting');

        try {
            eventSource = new EventSource('api/scan_stream.php');

            eventSource.addEventListener('connected', function(e) {
                isConnected = true;
                setIndicatorStatus('connected');
            });

            eventSource.addEventListener('qid_scan', function(e) {
                try {
                    const data = JSON.parse(e.data);
                    showScanPopup(data);
                } catch (err) {
                    console.error('Error parsing scan event:', err);
                }
            });

            eventSource.onerror = function(err) {
                isConnected = false;
                setIndicatorStatus('standby');
                eventSource.close();
                // Reconnect after 3 seconds or fallback to poll
                setTimeout(initScannerStream, 3000);
            };
        } catch (e) {
            startPollingFallback();
        }
    }

    // Polling fallback if SSE is blocked
    function startPollingFallback() {
        if (fallbackPollTimer) return;
        setIndicatorStatus('connected');

        fallbackPollTimer = setInterval(function() {
            fetch('api/scan_poll.php')
                .then(res => res.json())
                .then(data => {
                    if (data && data.has_scan && data.payload) {
                        showScanPopup(data.payload);
                    }
                })
                .catch(err => {});
        }, 3000);
    }

    // Start on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initScannerStream);
    } else {
        initScannerStream();
    }
})();
