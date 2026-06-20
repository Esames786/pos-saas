{{--
    Finance branch multi-select — thin wrapper kept for backward compatibility.
    The canonical implementation now lives in tenant.partials.branch-multiselect
    (class-based, no duplicate IDs, single JS push). Finance views include this
    path; do not duplicate logic here.

    Props: $branches, $selectedBranchIds
--}}
@include('tenant.partials.branch-multiselect', [
    'branches'          => $branches,
    'selectedBranchIds' => $selectedBranchIds ?? [],
    'colClass'          => 'col-sm-4',
])
