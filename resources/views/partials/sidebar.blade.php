<div class="sidebar" id="sidebar">
    <div class="sidebar-logo">
        <a href="{{ url('/dashboard') }}" class="logo logo-normal">
            <img src="{{ asset('assets/img/logo.svg') }}" alt="Logo">
        </a>
        <a href="{{ url('/dashboard') }}" class="logo logo-white">
            <img src="{{ asset('assets/img/logo-white.svg') }}" alt="Logo">
        </a>
        <a id="toggle_btn" href="javascript:void(0);">
            <i data-feather="chevrons-left" class="feather-16"></i>
        </a>
    </div>

    <div class="sidebar-inner slimscroll">
        <div id="sidebar-menu" class="sidebar-menu">
            <ul>
                <li class="submenu-open">
                    <h6 class="submenu-hdr">Main</h6>
                    <ul>
                        @can('central.dashboard')
                            <li>
                                <a href="{{ route('central.dashboard') }}">
                                    <i class="ti ti-layout-grid fs-16 me-2"></i>
                                    <span>Central Dashboard</span>
                                </a>
                            </li>
                        @endcan

                        @can('tenant.dashboard')
                            <li>
                                <a href="{{ url('/dashboard') }}">
                                    <i class="ti ti-layout-grid fs-16 me-2"></i>
                                    <span>Dashboard</span>
                                </a>
                            </li>
                        @endcan
                    </ul>
                </li>

                @can('central.routes.sync')
                    <li class="submenu-open">
                        <h6 class="submenu-hdr">Platform</h6>
                        <ul>
                            <li>
                                <form method="POST" action="{{ route('central.routes.sync') }}">
                                    @csrf
                                    <button class="btn btn-link text-start w-100 text-decoration-none">
                                        <i class="ti ti-refresh fs-16 me-2"></i>
                                        <span>Sync Routes</span>
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </li>
                @endcan
            </ul>
        </div>
    </div>
</div>
