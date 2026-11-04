@extends('layouts.supplier')
@section('title', 'Audit Trail')
@section('content')
    <div class="report-page" id="auditPage">
        <div class="report-card">
            <div class="report-header">
                <div>
                    <h1>Audit Trail</h1>
                    <p>Review system activity and changes to operational records.</p>
                </div>
                <div class="report-export-actions">
                    <button type="button" data-export="pdf"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>
                        PDF</button>
                    <button type="button" data-export="excel"><i class="bi bi-file-earmark-excel" aria-hidden="true"></i>
                        Excel</button>
                    <button type="button" data-export="print"><i class="bi bi-printer" aria-hidden="true"></i>
                        Print</button>
                </div>
            </div>
            <p class="report-notice">Admin-only activity review. These are automatically seeded sample events; backend
                logging and access enforcement are not connected yet. Passwords and authentication secrets must never be
                logged.</p>
            <div class="report-toolbar">
                <div class="report-length"><label for="auditLength">Show</label><select id="auditLength">
                        <option>5</option>
                        <option>10</option>
                        <option>25</option>
                        <option>50</option>
                    </select><span>entries</span></div>
                <div class="report-search"><label class="visually-hidden" for="auditSearch">Search audit activity</label><i
                        class="bi bi-search" aria-hidden="true"></i><input id="auditSearch" type="search"
                        placeholder="Search user, action, module or record..." autocomplete="off"></div>
            </div>
            <div class="report-table-container" id="auditTableHost"></div>
            <p class="report-status" id="auditStatus" role="status"></p>
        </div>
    </div>
    <div class="modal fade" id="auditViewModal" tabindex="-1" aria-labelledby="auditViewTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content audit-modal">
                <div class="modal-header">
                    <h5 class="modal-title" id="auditViewTitle">Activity Details</h5><button type="button" class="btn-close"
                        data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="auditViewContent"></div>
                <div class="modal-footer"><button type="button" class="audit-close" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
@endsection