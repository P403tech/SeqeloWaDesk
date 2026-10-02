<?php

namespace App\Console\Commands;

use App\Models\Contact;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Merge DUPLICATE contacts that share the same phone number within a workspace.
 *
 * Duplicates arise from older imports and from the pre-fix flow "Tag contact"
 * bug that spawned a phantom contact when a WABA number's dialing shape didn't
 * match the stored one. The symptom: a flow tags one copy while the operator is
 * looking at the other, so "tags never change".
 *
 * Survivor = the copy a conversation already points to, else the one with the
 * most tags, else the oldest (lowest id). Everything the duplicates own — tags,
 * groups, conversations, deals, and every other row keyed by contact_id — is
 * moved onto the survivor, then the duplicates are deleted.
 *
 * DRY-RUN BY DEFAULT. Nothing is written unless you pass --apply.
 *   php artisan contacts:dedupe 27           # preview
 *   php artisan contacts:dedupe 27 --apply    # merge for real
 *   php artisan contacts:dedupe --all --apply # every workspace
 */
class ContactsDedupeCommand extends Command
{
    protected $signature = 'contacts:dedupe {workspace? : Workspace id} {--all : Every workspace} {--apply : Actually merge (default is dry-run)}';

    protected $description = 'Merge duplicate contacts that share a phone number (dry-run unless --apply).';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $wsIds = $this->option('all')
            ? Contact::query()->select('workspace_id')->distinct()->pluck('workspace_id')->all()
            : [(int) $this->argument('workspace')];

        if (!$this->option('all') && (int) $this->argument('workspace') <= 0) {
            $this->error('Pass a workspace id, or --all.');
            return self::FAILURE;
        }

        // Every other table that references a contact by contact_id — repointed
        // generically so nothing is orphaned when a duplicate is removed.
        $fkTables = $this->tablesWithContactId();
        $this->line('Repointing contact_id in: ' . implode(', ', $fkTables));
        $this->line($apply ? '<comment>APPLY mode — writing changes.</comment>' : '<info>DRY-RUN — no changes. Add --apply to merge.</info>');

        $totalGroups = 0; $totalMerged = 0;

        foreach ($wsIds as $wsId) {
            $wsId = (int) $wsId;
            if ($wsId <= 0) continue;

            // Bucket contacts by canonical phone.
            $buckets = [];
            foreach (Contact::where('workspace_id', $wsId)->get() as $c) {
                $canon = Contact::canonicalizePhone($c->country_code, $c->mobile);
                if ($canon === '') continue; // social contacts w/o phone — never merge here
                $buckets[$canon][] = $c;
            }

            foreach ($buckets as $canon => $group) {
                if (count($group) < 2) continue;
                $totalGroups++;

                $survivor = $this->pickSurvivor($group);
                $dupes    = array_values(array_filter($group, fn ($c) => $c->id !== $survivor->id));
                $dupeIds  = array_map(fn ($c) => (int) $c->id, $dupes);

                $this->line('');
                $this->line("ws {$wsId}  +{$canon}  → keep #{$survivor->id} \"{$this->nm($survivor)}\""
                    . '  merge [' . implode(', ', array_map(fn ($c) => '#' . $c->id . ' "' . $this->nm($c) . '"', $dupes)) . ']');

                if (!$apply) { continue; }

                DB::transaction(function () use ($survivor, $dupes, $dupeIds, $fkTables) {
                    // 1) Union tags onto survivor, then drop the dupes' pivot rows.
                    $tagIds = DB::table('contact_tag')->whereIn('contact_id', $dupeIds)->pluck('tag_id')->unique()->all();
                    if ($tagIds) {
                        $survivor->tags()->syncWithoutDetaching($tagIds);
                        DB::table('contact_tag')->whereIn('contact_id', $dupeIds)->delete();
                    }

                    // 2) Union audience groups (encrypted array column on the row).
                    $groups = is_array($survivor->contact_group) ? $survivor->contact_group : [];
                    foreach ($dupes as $d) {
                        foreach ((is_array($d->contact_group) ? $d->contact_group : []) as $g) { $groups[] = (int) $g; }
                    }
                    $groups = array_values(array_unique(array_filter(array_map('intval', $groups))));

                    // 3) Fill blank survivor fields from a dupe (name/email/etc.).
                    foreach (['name', 'email'] as $f) {
                        if ((string) ($survivor->{$f} ?? '') === '') {
                            foreach ($dupes as $d) {
                                if ((string) ($d->{$f} ?? '') !== '') { $survivor->{$f} = $d->{$f}; break; }
                            }
                        }
                    }
                    $survivor->contact_group = $groups;
                    $survivor->save();

                    // 4) Repoint every other contact_id reference to the survivor.
                    foreach ($fkTables as $t) {
                        DB::table($t)->whereIn('contact_id', $dupeIds)->update(['contact_id' => $survivor->id]);
                    }

                    // 5) Remove the duplicate rows (raw delete — skip model events
                    //    so no flow trigger fires for a merge).
                    DB::table('contacts')->whereIn('id', $dupeIds)->delete();
                });

                $totalMerged += count($dupes);
            }
        }

        $this->line('');
        $this->info("Duplicate groups found: {$totalGroups}. Contacts "
            . ($apply ? "merged away: {$totalMerged}." : "that WOULD be merged: {$totalMerged} (dry-run)."));
        return self::SUCCESS;
    }

    /** Survivor: a copy a conversation already points to, else most tags, else oldest. */
    private function pickSurvivor(array $group): Contact
    {
        $linkedIds = DB::table('conversations')
            ->whereIn('contact_id', array_map(fn ($c) => (int) $c->id, $group))
            ->pluck('contact_id')->map(fn ($v) => (int) $v)->unique()->all();

        usort($group, function (Contact $a, Contact $b) use ($linkedIds) {
            $la = in_array((int) $a->id, $linkedIds, true) ? 1 : 0;
            $lb = in_array((int) $b->id, $linkedIds, true) ? 1 : 0;
            if ($la !== $lb) return $lb <=> $la;                    // linked wins
            $ta = $a->tags()->count(); $tb = $b->tags()->count();
            if ($ta !== $tb) return $tb <=> $ta;                    // more tags wins
            return $a->id <=> $b->id;                               // oldest wins
        });
        return $group[0];
    }

    private function nm(Contact $c): string
    {
        return mb_substr((string) ($c->name ?: '—'), 0, 24);
    }

    /** All tables (except contacts/contact_tag, handled specially) with a contact_id column. */
    private function tablesWithContactId(): array
    {
        $db = DB::getDatabaseName();
        $rows = DB::table('information_schema.columns')
            ->where('table_schema', $db)
            ->where('column_name', 'contact_id')
            ->pluck('table_name')
            ->map(fn ($t) => (string) $t)
            ->reject(fn ($t) => in_array($t, ['contacts', 'contact_tag'], true))
            ->values()->all();
        return $rows;
    }
}
