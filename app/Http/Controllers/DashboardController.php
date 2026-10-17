<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\InventoryCampaign;
use App\Models\LicenseInstance;
use App\Models\Location;
use App\Models\SoftwareProduct;
use App\Models\WorkOrder;

class DashboardController extends Controller
{
    public function __invoke()
    {
        return view('dashboard', ['counts' => ['Оборудование' => Asset::whereNull('archived_at')->where('status', '!=', 'written_off')->count(), 'Кабинеты' => Location::whereNull('archived_at')->count(), 'Программные продукты' => SoftwareProduct::whereNull('archived_at')->count(), 'Лицензии' => LicenseInstance::whereNull('archived_at')->count()], 'works' => WorkOrder::with('asset')->whereNotIn('status', ['closed', 'cancelled'])->orderBy('due_on')->limit(6)->get(), 'campaigns' => InventoryCampaign::whereIn('status', ['active', 'reconciling'])->limit(5)->get()]);
    }
}
