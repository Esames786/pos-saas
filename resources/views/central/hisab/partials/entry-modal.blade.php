{{-- MASTER-HISAB-1 — entry ka popup. Nayi entry aur purani badalna dono yahin (Add = Edit). --}}
<div class="modal fade" id="hsEntryModal" tabindex="-1" aria-labelledby="hsEntryTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form class="modal-content" id="hsEntryForm" novalidate>
            <div class="modal-header">
                <h5 class="modal-title" id="hsEntryTitle">Nayi entry</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Band karein"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="_id" value="">
                <input type="hidden" name="_was" value="">

                <div class="alert alert-danger d-none" role="alert" data-hs-errors></div>

                {{-- 1. Kya hua --}}
                <div class="mb-3">
                    <div class="form-label fw-semibold">Kya hua?</div>
                    <div class="hs-type-grid" role="radiogroup" aria-label="Entry ki qism">
                        <div class="hs-type">
                            <input type="radio" name="type" id="hsTypeIn" value="in">
                            <label for="hsTypeIn">
                                <div class="hs-type-emoji" aria-hidden="true">💰</div>
                                <div class="hs-type-title">Paisa aaya</div>
                                <div class="hs-type-help">Client ne ya kahin se paisa diya</div>
                            </label>
                        </div>
                        <div class="hs-type">
                            <input type="radio" name="type" id="hsTypeOut" value="out">
                            <label for="hsTypeOut">
                                <div class="hs-type-emoji" aria-hidden="true">🧾</div>
                                <div class="hs-type-title">Kharcha</div>
                                <div class="hs-type-help">Company ka koi bill diya (Claude, server, WhatsApp…)</div>
                            </label>
                        </div>
                        <div class="hs-type">
                            <input type="radio" name="type" id="hsTypeTransfer" value="transfer">
                            <label for="hsTypeTransfer">
                                <div class="hs-type-emoji" aria-hidden="true">🔁</div>
                                <div class="hs-type-title">Khate se khate</div>
                                <div class="hs-type-help">Ek owner ne doosre ko diya — nafa nahi badalta</div>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-sm-5">
                        <label class="form-label" for="hsDate">Tareekh</label>
                        <input type="date" class="form-control" id="hsDate" name="entry_date" required>
                    </div>
                    <div class="col-sm-7" data-hs-only="in">
                        <label class="form-label" for="hsTenant">Kis client se? <span class="text-muted small">(zaroori nahi)</span></label>
                        <select class="form-select" id="hsTenant" name="tenant_id">
                            <option value="">— Koi client nahi —</option>
                            @foreach($tenants as $t)
                                <option value="{{ $t->id }}">{{ $t->business_name }} ({{ $t->tenant_code }})</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                {{-- 2a. Paisa aaya / kharcha: ek ya zyada hisse --}}
                <div class="mb-3" data-hs-only="in out">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <div class="form-label fw-semibold mb-0" data-hs-lines-title>Raqam</div>
                        <button type="button" class="btn btn-sm btn-light" data-hs-add-line><i class="ti ti-plus"></i> Ek aur hissa</button>
                    </div>
                    <div class="text-muted small mb-2" data-hs-lines-help></div>
                    <div data-hs-lines></div>
                </div>

                {{-- 2b. WhatsApp ka card wala bill --}}
                <div class="mb-3 d-none" data-hs-card>
                    <div class="form-label fw-semibold mb-1"><span aria-hidden="true">💬</span> WhatsApp ka card wala bill</div>
                    <div class="text-muted small mb-2">Bank ki SMS / statement se likhein. Kul raqam khud ban jayegi.</div>
                    <div class="row g-2">
                        <div class="col-6 col-md-3">
                            <label class="form-label small" for="hsUsd">Meta ka bill ($)</label>
                            <input type="number" step="0.0001" min="0" class="form-control" id="hsUsd" name="usd_amount" placeholder="1.35">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small" for="hsBill">Bank ne bill ka kata (Rs)</label>
                            <input type="number" step="0.01" min="0" class="form-control" id="hsBill" name="bill_amount" placeholder="378.66">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small" for="hsFee">Tax / fee (Rs)</label>
                            <input type="number" step="0.01" min="0" class="form-control" id="hsFee" name="bank_fee" placeholder="157">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small" for="hsMsgs">Kitne messages</label>
                            <input type="number" step="1" min="1" class="form-control" id="hsMsgs" name="message_count" placeholder="90">
                        </div>
                    </div>
                    <div class="small mt-2" data-hs-card-calc></div>
                </div>

                {{-- 2c. Khate se khate --}}
                <div class="row g-3 mb-3" data-hs-only="transfer">
                    <div class="col-sm-4">
                        <label class="form-label" for="hsFrom">Kis khate se gaya</label>
                        <select class="form-select" id="hsFrom" name="from_account"></select>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label" for="hsTo">Kis khate mein aaya</label>
                        <select class="form-select" id="hsTo" name="to_account"></select>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label" for="hsTransferAmount">Raqam (Rs)</label>
                        <input type="number" step="0.01" min="0" class="form-control" id="hsTransferAmount" name="transfer_amount">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="hsNote">Note <span class="text-muted small">(zaroori nahi)</span></label>
                    <textarea class="form-control" id="hsNote" name="note" rows="2" maxlength="2000" placeholder="Jaise: October ki fees"></textarea>
                </div>

                <div class="hs-summary" aria-live="polite" data-hs-summary>—</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Wapas</button>
                <button type="submit" class="btn btn-primary" data-hs-save><i class="ti ti-device-floppy"></i> Save karein</button>
            </div>
        </form>
    </div>
</div>
