<?php

namespace App\Services\Lifecycle;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Promotion;
use App\Models\Quote;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Wipes the business data of one subscription.
 *
 * The account itself survives: the subscription row, its branches, the primary
 * user and everything needed to sign in again stay untouched. Every query is
 * scoped by branch_id (or subscription_id) so other tenants are never touched.
 *
 * Deletion follows the foreign keys (children before parents). Models that own
 * media files are deleted one row at a time, because MediaLibrary only removes
 * the files from disk through the "deleting" event of the model.
 */
class TenantDataPurgeService
{
    public function __construct(private readonly AccountLifecycleService $lifecycleService) {}

    /**
     * @return array<string, int> Deleted rows per entity.
     */
    public function purge(Subscription $subscription): array
    {
        $branchIds = $subscription->branches()->pluck('id')->all();
        $primaryUser = $this->lifecycleService->resolvePrimaryUser($subscription);

        return DB::transaction(function () use ($subscription, $branchIds, $primaryUser): array {
            $summary = [];

            // CFDI documents first: they reference transactions, customers and
            // products, and the acceptor responses point to the branch.
            $summary['accept_reject_responses'] = DB::table('accept_reject_responses')
                ->whereIn('branch_id', $branchIds)
                ->delete();
            $summary['invoices'] = $this->deleteInvoices($branchIds);

            // Storefront orders: items and status logs cascade.
            $summary['orders'] = Order::query()
                ->where('subscription_id', $subscription->id)
                ->delete();

            // Service evidence lives in media collections (disk files).
            $summary['service_orders'] = $this->deleteWithMedia(
                ServiceOrder::query()->whereIn('branch_id', $branchIds)
            );

            $summary['quotes'] = Quote::query()->whereIn('branch_id', $branchIds)->delete();

            // Sales: items, payments, promotion pivots and layaway data cascade.
            $summary['transactions'] = Transaction::query()->whereIn('branch_id', $branchIds)->delete();

            $summary['customers'] = Customer::query()->whereIn('branch_id', $branchIds)->delete();

            // Promotions only make sense while the catalog exists.
            $summary['promotions'] = Promotion::query()
                ->where('subscription_id', $subscription->id)
                ->delete();

            $summary['services'] = $this->deleteWithMedia(
                Service::query()->whereIn('branch_id', $branchIds)
            );

            $summary['products'] = $this->deleteProducts($branchIds);

            $summary['users'] = $this->deleteSecondaryUsers($branchIds, $primaryUser);

            return $summary;
        });
    }

    /**
     * Invoices keep plain file paths (not MediaLibrary), so the XML stored on
     * disk has to be removed explicitly. The PDF may be an external PAC URL.
     *
     * @param  array<int, int>  $branchIds
     */
    private function deleteInvoices(array $branchIds): int
    {
        $disk = Storage::disk('public');

        DB::table('invoices')
            ->whereIn('branch_id', $branchIds)
            ->select(['id', 'xml_url', 'pdf_url'])
            ->orderBy('id')
            ->chunk(200, function ($invoices) use ($disk): void {
                $files = [];

                foreach ($invoices as $invoice) {
                    if ($invoice->xml_url) {
                        $files[] = $invoice->xml_url;
                    }

                    if ($invoice->pdf_url && ! str_starts_with($invoice->pdf_url, 'http')) {
                        $files[] = $invoice->pdf_url;
                    }
                }

                if ($files !== []) {
                    $disk->delete($files);
                }
            });

        return DB::table('invoices')->whereIn('branch_id', $branchIds)->delete();
    }

    /**
     * Delete models one by one so MediaLibrary also removes their files from
     * disk. chunkById keeps memory flat and stays safe while deleting.
     */
    private function deleteWithMedia(Builder $query): int
    {
        $deleted = 0;
        $chunkSize = max(1, (int) config('account_lifecycle.inactivity.chunk_size', 100));

        $query->chunkById($chunkSize, function ($models) use (&$deleted): void {
            foreach ($models as $model) {
                $model->delete();
                $deleted++;
            }
        });

        return $deleted;
    }

    /**
     * Products own images and can be the parent of a kit. Kit components are
     * polymorphic (no foreign key), so references to the deleted variants are
     * removed explicitly.
     *
     * @param  array<int, int>  $branchIds
     */
    private function deleteProducts(array $branchIds): int
    {
        $productIds = Product::query()
            ->whereIn('branch_id', $branchIds)
            ->pluck('id')
            ->all();

        if ($productIds === []) {
            return 0;
        }

        $attributeIds = DB::table('product_attributes')
            ->whereIn('product_id', $productIds)
            ->pluck('id')
            ->all();

        $this->deleteKitComponents((new Product)->getMorphClass(), $productIds);
        $this->deleteKitComponents((new ProductAttribute)->getMorphClass(), $attributeIds);

        return $this->deleteWithMedia(Product::query()->whereIn('id', $productIds));
    }

    /**
     * @param  array<int, int>  $componentIds
     */
    private function deleteKitComponents(string $componentType, array $componentIds): void
    {
        if ($componentIds === []) {
            return;
        }

        DB::table('product_components')
            ->where('componentable_type', $componentType)
            ->whereIn('componentable_id', $componentIds)
            ->delete();
    }

    /**
     * Remove every user except the account owner, together with the tokens,
     * sessions and profile photo that would otherwise keep access alive.
     *
     * @param  array<int, int>  $branchIds
     */
    private function deleteSecondaryUsers(array $branchIds, ?User $primaryUser): int
    {
        // Without an owner there is no safe user to keep, so nothing is deleted.
        if (! $primaryUser) {
            return 0;
        }

        $deleted = 0;

        User::query()
            ->whereIn('branch_id', $branchIds)
            ->whereKeyNot($primaryUser->id)
            ->orderBy('id')
            ->chunkById(50, function ($users) use ($primaryUser, &$deleted): void {
                foreach ($users as $user) {
                    $this->releaseUserReferences($user, $primaryUser);
                    $this->deleteUserAccessArtifacts($user);
                    $user->delete();
                    $deleted++;
                }
            });

        return $deleted;
    }

    /**
     * Move non-nullable or cascade references to the owner so history that
     * belongs to the account (stamp requests, referral codes) is preserved.
     */
    private function releaseUserReferences(User $user, User $primaryUser): void
    {
        DB::table('stamp_purchases')
            ->where('requested_by_user_id', $user->id)
            ->update(['requested_by_user_id' => $primaryUser->id]);

        DB::table('referral_codes')
            ->where('user_id', $user->id)
            ->update(['user_id' => $primaryUser->id]);
    }

    private function deleteUserAccessArtifacts(User $user): void
    {
        $morphClass = $user->getMorphClass();
        $tableNames = config('permission.table_names', []);

        DB::table('sessions')->where('user_id', $user->id)->delete();
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();

        DB::table('personal_access_tokens')
            ->where('tokenable_type', $morphClass)
            ->where('tokenable_id', $user->id)
            ->delete();

        DB::table($tableNames['model_has_roles'] ?? 'model_has_roles')
            ->where('model_type', $morphClass)
            ->where('model_id', $user->id)
            ->delete();

        DB::table($tableNames['model_has_permissions'] ?? 'model_has_permissions')
            ->where('model_type', $morphClass)
            ->where('model_id', $user->id)
            ->delete();

        if ($user->profile_photo_path) {
            Storage::disk(config('jetstream.profile_photo_disk', 'public'))
                ->delete($user->profile_photo_path);
        }
    }
}
