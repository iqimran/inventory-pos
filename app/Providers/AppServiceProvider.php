<?php

namespace App\Providers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\ServiceInvoice;
use App\Models\ServiceJob;
use App\Models\Subcategory;
use App\Models\Unit;
use App\Models\User;
use App\Policies\CatalogPolicy;
use App\Support\Database\BlueprintMacros;
use App\Support\OrganizationProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        BlueprintMacros::register();

        // One instance per request, so shared props, the root view and pages read the settings once.
        $this->app->scoped(OrganizationProfile::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $production = $this->app->isProduction();

        // Surface lazy loading (N+1), silently discarded attributes and missing attributes outside production.
        Model::shouldBeStrict(! $production);

        // Guard against `migrate:fresh`, `db:wipe` etc. against the production database.
        DB::prohibitDestructiveCommands($production);

        Password::defaults(fn () => $production
            ? Password::min(10)->letters()->mixedCase()->numbers()
            : Password::min(8));

        // Stable aliases for polymorphic references (ledger, stock movements, allocations),
        // so stored history does not depend on PHP class names.
        Relation::morphMap([
            'party' => Party::class,
            'payment' => Payment::class,
            'product' => Product::class,
            'purchase' => Purchase::class,
            'purchase_return' => PurchaseReturn::class,
            'sale' => Sale::class,
            'sale_return' => SaleReturn::class,
            'service_job' => ServiceJob::class,
            'service_invoice' => ServiceInvoice::class,
        ]);

        foreach ([Category::class, Subcategory::class, Brand::class, Unit::class, Product::class] as $model) {
            Gate::policy($model, CatalogPolicy::class);
        }

        // Admin has full access to every ability.
        Gate::before(fn (User $user) => $user->isAdmin() ? true : null);
    }
}
