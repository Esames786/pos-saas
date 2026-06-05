<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Http\Controllers\Controller;
use App\Models\Tenant\ManagerApproval;
use Illuminate\Http\Request;

class AuditReportController extends Controller
{
    public function managerApprovals(Request $request)
    {
        $filters = [
            'date_from'    => $request->input('date_from', today()->subDays(29)->format('Y-m-d')),
            'date_to'      => $request->input('date_to',   today()->format('Y-m-d')),
            'action_type'  => $request->input('action_type'),
        ];

        $query = ManagerApproval::query()
            ->with(['requestedBy', 'approvedBy'])
            ->when(!empty($filters['action_type']),
                fn ($q) => $q->where('action_type', $filters['action_type']))
            ->when(!empty($filters['date_from']),
                fn ($q) => $q->whereDate('approved_at', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']),
                fn ($q) => $q->whereDate('approved_at', '<=', $filters['date_to']))
            ->orderByDesc('approved_at');

        $approvals = $query->paginate(25)->withQueryString();
        $actionTypes = ['manual_discount', 'void_item', 'refund', 'override'];

        if ($request->boolean('export_csv')) {
            return response()->streamDownload(function () use ($query) {
                $fp = fopen('php://output', 'w');
                fputcsv($fp, ['Approval No', 'Action Type', 'Requested By', 'Approved By', 'Amount', 'Reason', 'Approved At']);
                foreach ($query->get() as $a) {
                    fputcsv($fp, [
                        $a->approval_no, $a->action_type, $a->requestedBy?->name, $a->approvedBy?->name,
                        $a->amount, $a->reason, $a->approved_at?->format('Y-m-d H:i'),
                    ]);
                }
                fclose($fp);
            }, 'manager-approvals-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv']);
        }

        return view('tenant.reports.audit.manager-approvals', compact('approvals', 'filters', 'actionTypes'));
    }
}
