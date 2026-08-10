# Acceptance gate findings — jpkcom-acf-jobs 1.4.0

38 agents, five abuse lenses, every finding handed to a second agent tasked with refuting it.
25 survived refutation; 8 were refuted.

Full machine-readable record: `acceptance-gate-findings.json`.

## [important] pre_get_posts can delete the whole meta_query and the response still reports the filter as applied — HTTP 200, neither guard fires

**Lens:** verify-last-round

**Consequence:** The executed statement contains no job_type LIKE clause and no expiry clause, yet filters.job_type still says ["FULL_TIME"] and the status is 200. This is verbatim the defect the feature exists to prevent — "a dropped clause returns the whole corpus while claiming to be filtered" — and both returned-result checks pass by construction: count(posts)=5 is not > per_page 5, and total 42 is not < 5. The docblock at includes/abilities.php:2830-2842 states "Every clause the ability built ... has to be present unchanged in what is executed"; that is true only of the argument filter. Any site with a pre_get_posts handler that does not exclude this query (post_type=job, not the main query, no is_admin guard — the plugin's own archive.php registers one) hands an MCP client a whole-corpus answer labelled as a filtered one. The same run also corrupts the visibility block: the two count queries lose their clauses too (SQL[1]/SQL[2] degrade to a bare published-jobs count).

**Reproduction:**

```
cp scratchpad/pgp-drop-meta.php /home/jpk/ddev/posts/wp-content/plugins/jpkcom-acf-jobs/tools/zz-lens.php
cd /home/jpk/ddev/posts && ddev wp eval-file wp-content/plugins/jpkcom-acf-jobs/tools/zz-lens.php

The script calls jpkcom_acf_jobs_ability_query_jobs([ 'job_type'=>['FULL_TIME'], 'per_page'=>5 ]) twice in one process, the second time with one hook added:
  add_action('pre_get_posts', function($q){ if($q->get('post_type')!=='job') return; $q->set('meta_query',[]); }, 99);

== untouched ==
  filters={"include_closed":true,"order":"DESC","job_type":["FULL_TIME"]}
  total=21 per_page=5 page=1 total_pages=5 jobs=5
  SQL[0]: ... AND ( ( mt4.meta_key = 'job_type' AND mt4.meta_value LIKE '%"FULL\_TIME"%' ) ) ) ) AND ...post_password = '' ... LIMIT 0, 5

== pre_get_posts sets meta_query to [] ==
  filters={"include_closed":true,"order":"DESC","job_type":["FULL_TIME"]}
  total=42 per_page=5 page=1 total_pages=9 jobs=5
  SQL[0]: SELECT SQL_CALC_FOUND_ROWS jpkA11yTpl1_posts.* FROM jpkA11yTpl1_posts INNER JOIN jpkA11yTpl1_postmeta ON ( jpkA11yTpl1_posts.ID = jpkA11yTpl1_postmeta.post_id ) WHERE 1=1 AND ( jpkA11yTpl1_postmeta.meta_key = 'job_featured' ) AND ...post_type = 'job' AND ((...post_status = 'publish')) GROUP BY ... LIMIT 0, 5

Over HTTP, same hook as an mu-plugin:
curl -sk -g -u 'abilitysub:***' 'https://posts.ddev.site/wp-json/wp-abilities/v1/abilities/jpkcom-acf-jobs/query-jobs/run?input[job_type][]=FULL_TIME&input[per_page]=10'
HTTP 200
filters {'include_closed': True, 'order': 'DESC', 'job_type': ['FULL_TIME']}   total 37 (baseline 21)
```

**Refuter's verdict:** Reproduced independently on /home/jpk/ddev/posts against the committed tree, both in-process and over HTTP. A pre_get_posts callback that sets meta_query on post_type=job removes every clause the ability built; the response is HTTP 200 with filters.job_type still ["FULL_TIME"] while returning PART_TIME (185,188), CONTRACTOR (186,189) and an is_expired=true job (415). Measured: total 2 -> 13 (CLI) and 2 -> 8 (HTTP). Executed SQL under the hook retains only "AND ( postmeta.meta_key = 'job_featured' )" plus post_password/post_type/post_status: no job_type LIKE, no expiry, no job_closed clause. Same defect at the tax leg: attribute=firmenwagen goes 4 -> 10 with jobs whose attrs=[]. No guard stops it: unbacked_claim (abilities.php:2805) and query_divergence (:2913) both run before new WP_Query (:2929), and the returned-result guard (:2960-2996) tests only count(posts)>per_page and total<count(posts), neither of which a dropped clause trips; the reader gate at :3030 does not re-check the axis or expiry. Not covered by any assertion: the suite is 453 passed / 0 failed and tests/test-abilities.php:1671-1704 covers only posts_per_archive_page and no_found_rows; the harness already models the case in one line and a scratch test drops meta_query to [] and returns a non-WP_Error with filters claiming FULL_TIME. Two corrections to the finding. (1) Its reachability evidence is FALSE: it cites the plugin's own archive.php handler, but includes/archive.php:39 is guarded by !is_admin() && is_main_query() && is_post_type_archive('job'); a marker with the byte-identical guard admitted the ability query 0 times in all six runs, so a stock install is unaffected. (2) Its SQL quote wrongly omits post_password='' (a query var, not a meta clause, and it survives), and its numbers 21/42/37 do not reproduce because the instance corpus was polluted by concurrent lens agents. A stronger reachability argument replaces the false one: the destructive set('meta_query', []) is unnecessary. The ordin

---

## [important] visibility.hidden_expired counts a job the visibility rule actually lists, so listed_total + hidden_expired can exceed published_total

**Lens:** disclosure

**Consequence:** A caller reading `visibility` to explain the gap between published_total and listed_total is told that more jobs are hidden by expiry than are actually hidden at all, and the two numbers can sum past the corpus size. A job with an empty (rather than absent) job_expiry_date row is reported as an expired, hidden job in the same response that lists it. No disclosure — a wrong count, in the block whose whole purpose is to make the visibility rule auditable.

**Reproduction:**

```
Outside my lens (I hit it while cross-checking my own fixture counts) but it has a clean reproduction. A published job with an EMPTY job_expiry_date meta row is returned by jpkcom_acf_jobs_build_job_query_args() and simultaneously matched by the hidden_expired count query at includes/abilities.php:645:

  $ ddev wp eval '
    $pub = get_posts(["post_type"=>"job","post_status"=>"publish","posts_per_page"=>-1,"fields"=>"ids","has_password"=>false]);
    $args = jpkcom_acf_jobs_build_job_query_args(["post_status"=>"publish","posts_per_page"=>-1,"exclude_password_protected"=>true]);
    $args["fields"]="ids"; $q = new WP_Query($args);
    $exp = new WP_Query(["post_type"=>"job","post_status"=>"publish","has_password"=>false,"posts_per_page"=>-1,"fields"=>"ids",
      "meta_query"=>["relation"=>"AND",["key"=>"job_featured","compare"=>"EXISTS"],
      ["key"=>"job_expiry_date","value"=>current_time("Y-m-d"),"compare"=>"<","type"=>"DATE"]]]);
    printf("published=%d listed=%d expired_count=%d\n", count($pub), count($q->posts), count($exp->posts));
    foreach(array_intersect($q->posts,$exp->posts) as $b)
      echo "  $b  ".get_the_title($b)."  expiry=".var_export(get_post_meta($b,"job_expiry_date",true),true)."\n";'

  published=46 listed=30 expired_count=17
  listed AND counted as expired: 397,315
    397  NUMLENS leeres Ablaufdatum  expiry=''
    315  LENSJOB exp emptystring  expiry=''

MariaDB casts '' to a zero date, and '0000-00-00' < '2026-08-04' is true, so an empty expiry row satisfies the < DATE comparison while the visibility rule's own OR branch for an empty value keeps the job listed. Visible in the ability output as arithmetic that does not close: an earlier list-filters call on the same instance reported published_total 37, listed_total 25, hidden_missing_featured 0, hidden_expired 13 — 25 + 13 = 38 > 37.
```

**Refuter's verdict:** Reproduced independently on /home/jpk/ddev/posts against the committed abilities-api tree (HEAD is actually 5931479; 181f56f is two commits back). I did not trust the quoted eval and re-ran everything.

REPRODUCTION. Baseline jobs 184-189 carry NO job_expiry_date row at all (verified: SELECT ... FROM jpkA11yTpl1_postmeta WHERE post_id BETWEEN 184 AND 189 AND meta_key LIKE '%job_expiry%' returns empty), so the pristine corpus does not show it. One fixture is enough: ddev wp eval '$id=wp_insert_post([...,"post_title"=>"REFEMPTYEXP"]); update_field("job_featured",0,$id); update_field("job_expiry_date","",$id);' -> created 440, raw row job_expiry_date = []. The lens's verbatim eval then prints: published=9 listed=8 expired_count=2 / "  440  REFEMPTYEXP  expiry=''".

CONSEQUENCE IS REAL AT THE ABILITY OUTPUT, NOT JUST IN AN EVAL. ddev wp eval 'wp_set_current_user(1); print_r(wp_get_ability("jpkcom-acf-jobs/list-filters")->execute([])["visibility"]);' returned published_total=10, listed_total=10, hidden_missing_featured=0, hidden_expired=4. listed_total EQUALS published_total, i.e. nothing is hidden at all, while the same block claims 4 jobs are excluded by expiry; 10+0+4=14 > 10. The schema at includes/abilities.php:1707 promises literally "The two shortfall causes partition the difference exactly." query-jobs contradicts itself inside one response: total=10, visibility={"hidden_missing_featured":0,"hidden_expired":4}, with 440/442/443/444 all present in the jobs array. get-job 440 answers listed=true, not_listed_reason=NULL, is_expired=false, so the per-job path is correct and the aggregate is wrong by the plugin's own reckoning.

NO GUARD. jpkcom_acf_jobs_ability_visibility_counts() (line 624) returns the two jpkcom_acf_jobs_ability_count_query() results straight into the response at lines 2358-2363 (list-filters) and 3066 (query-jobs) with no post-processing. The only relevant filter, jpkcom_acf_jobs_ability_query_args at line 2870, applies to the main query and never

---

## [important] hidden_expired counts every job whose expiry date is empty — i.e. every job that has no expiry date — while the same response lists those jobs

**Lens:** wrong-answers

**Consequence:** Any caller of list-filters on a site whose jobs carry no expiry date — the ordinary case as soon as the ACF date field has been saved once, which stores '' rather than deleting the row — is told that those jobs are "Published jobs excluded because their expiry date has passed" in the same response that lists them. On test2 that is 2 of 2, i.e. 100% of the corpus reported as expired and as listed simultaneously. The output_schema states "The two shortfall causes partition the difference exactly" (abilities.php:1708); the arithmetic goes the wrong way (sum exceeds the shortfall), so an agent that computes published_total - listed_total - hidden_missing_featured - hidden_expired gets a negative residual, and an agent that reports "most of this site's jobs have expired" is stating the opposite of the truth. No caller input, no site callback and no unusual data are needed.

**Reproduction:**

```
On /home/jpk/ddev/test2, on data I did not create and did not touch:

  $ cd /home/jpk/ddev/test2 && ddev wp eval '
  foreach ([171,195,349] as $id) echo "$id expiry=".var_export(get_post_meta($id,"job_expiry_date",true),true)." featured=".var_export(get_post_meta($id,"job_featured",true),true)."\n";
  $lf = jpkcom_acf_jobs_ability_list_filters(null);
  echo "list-filters visibility = ".json_encode($lf["visibility"])."\n";
  $args = jpkcom_acf_jobs_build_job_query_args(["post_status"=>"publish","posts_per_page"=>-1,"exclude_password_protected"=>true]);
  $args["fields"]="ids"; $listed=(new WP_Query($args))->posts; sort($listed);
  $he=(new WP_Query(["post_type"=>"job","post_status"=>"publish","has_password"=>false,"posts_per_page"=>-1,"fields"=>"ids",
    "meta_query"=>["relation"=>"AND",["key"=>"job_featured","compare"=>"EXISTS"],["key"=>"job_expiry_date","value"=>current_time("Y-m-d"),"compare"=>"<","type"=>"DATE"]]]))->posts; sort($he);
  echo "listed=".implode(",",$listed)."   counted as hidden_expired=".implode(",",$he)."\n";
  echo "in both = ".implode(",", array_intersect($listed,$he))."\n";'

  171 expiry='' featured='0'
  195 expiry='' featured='0'
  349 expiry='' featured=''
  list-filters visibility = {"published_total":2,"listed_total":2,"hidden_missing_featured":0,"hidden_expired":2}
  listed=171,195   counted as hidden_expired=171,195
  in both = 171,195

Same defect over HTTP on /home/jpk/ddev/posts, with my own fixture 397 whose job_expiry_date is '':

  $ curl -skg -u "abilitysub:ErFkRzBH1zqq1VJGmZz4Hg5G" "https://posts.ddev.site/wp-json/wp-abilities/v1/abilities/jpkcom-acf-jobs/list-filters/run"
  {"published_total": 12, "listed_total": 9, "hidden_missing_featured": 1, "hidden_expired": 3}
  $ curl -skg -u "abilitysub:..." ".../jpkcom-acf-jobs/query-jobs/run?input[per_page]=50"
  total 9 ids [187, 184, 400, 397, 392, 189, 188, 186, 185]
  $ ddev wp eval 'echo var_export(get_post_meta(397,"job_expiry_date",true),true);'
  ''

Magnitude, measured by adding three more jobs whose only distinguishing meta is job_expiry_date = '' (exactly what ACF writes for a cleared date field) and then deleting them again:

  before: {"published_total":12,"listed_total":9,"hidden_missing_featured":1,"hidden_expired":3}
  created (expiry = ''): 411,412,413
  after : {"published_total":15,"listed_total":12,"hidden_missing_featured":1,"hidden_expired":6}
  the 3 new jobs are in the returned list: true
  partition: published(15)-listed(12)=3  vs  missing(1)+expi
```

**Refuter's verdict:** Reproduced independently and could not refute it on any axis. (1) The finding's script reproduces verbatim on /home/jpk/ddev/test2 against the committed tree: jobs 171 and 195 have job_expiry_date='' (site data, not mine), appear in the listed set, and are simultaneously counted by hidden_expired. Stronger than claimed: over HTTP as a plain subscriber with no input, list-filters answers {"published_total":5,"listed_total":5,"hidden_missing_featured":0,"hidden_expired":2} — the shortfall is exactly zero while 2 are reported hidden, so the schema's "the two shortfall causes partition the difference exactly" (abilities.php:1708) yields a residual of -2. (2) The mechanism is real, including the part that looked fabricated: I dumped the generated SQL and probed MariaDB 10.11.18 directly — CAST('' AS DATE) IS NULL is 1 AND CAST('' AS DATE) < '2026-08-04' is 1 at the same time (temporal comparison rewrite treats '' as 0000-00-00, with an "Incorrect datetime value" warning), so '' matches the mirror's '<' but not the visibility rule's '>=' — which is exactly why jobs-data.php needs its third "= ''" branch and why the mirror at abilities.php:645-664, copying only the first branch, inverts the verdict. (3) The precondition is the ordinary case, measured rather than assumed: acf_save_post() with the date_picker left empty writes job_expiry_date='' plus the _job_expiry_date reference row. The seeded fixtures on /home/jpk/ddev/posts escape it only because they carry no meta row at all (metadata_exists=false for 184-189), routing through NOT EXISTS. (4) No later layer stops it: count_query (line 586) just returns found_posts, and the broken counter reaches the wire from TWO callers, not one — list-filters (2346) and query-jobs (3066), i.e. every query-jobs response contains it too, which the finding under-reports. (5) Not covered: tests/test-abilities.php:571-574 asserts is_int() on both counts and nothing more; the suite has a dedicated mutant for the third expiry clause on the 

---

## [important] query-jobs' visibility block is site-wide but the schema calls it "excluded from this answer", so a filtered response reports hidden jobs that do not exist in the filtered set

**Lens:** wrong-answers

**Consequence:** The field is described as "Published jobs excluded from this answer by the site visibility rule rather than by the filters" (abilities.php:1840). A model reading a filtered response adds the numbers it was handed: "6 open positions at Testfirma GmbH shown, 4 more hidden" — or, with the larger corpus, "6 shown, 18 hidden, so about 24 positions exist there". The true answer is 6 published and 6 listed. The error scales with the rest of the site and is unbounded: the more unrelated expired jobs a site holds, the larger the phantom count reported next to an unrelated filtered total.

**Reproduction:**

```
  $ curl -skg -u "abilitysub:ErFkRzBH1zqq1VJGmZz4Hg5G" "https://posts.ddev.site/wp-json/wp-abilities/v1/abilities/jpkcom-acf-jobs/query-jobs/run?input[company][]=182&input[per_page]=50"
  {"filters": {"include_closed": true, "order": "DESC", "company": [182]}, "total": 6, "visibility": {"hidden_missing_featured": 1, "hidden_expired": 3}}

Ground truth for company 182 at the same moment:

  $ ddev wp eval 'global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key=\"job_company\" WHERE p.post_type=\"job\" AND p.post_status=\"publish\" AND p.post_password=\"\" AND m.meta_value LIKE \"%\\\"182\\\"%\"");'
  published jobs w/ company 182: 6

All 6 are listed (query-jobs total for that filter is 6), so the number of jobs at company 182 excluded by the visibility rule is exactly 0. The response reports 1 and 3. In-process confirmation:

  == visibility block next to a FILTERED total ==
    filters={"include_closed":true,"order":"DESC","company":[182]}
    total=6
    visibility={"hidden_missing_featured":1,"hidden_expired":17}
    TRUTH: published jobs with company 182 = 6 ; of those expired = 0
```

**Refuter's verdict:** Confirmed by code reading and two independent live reproductions. jpkcom_acf_jobs_ability_visibility_counts() (abilities.php:624) takes no parameters — its two WP_Query calls carry only post_type/post_status/has_password and a meta_query on job_featured/job_expiry_date, with no filter axis, no tax_query and no search — and it is called unconditionally at abilities.php:3066 regardless of the filters applied. I searched for the next-layer guard and there is none: no branch on $filters, nothing in the ability description, and nothing in CLAUDE.md (trap 8 explains only why each cause needs its own query, never that the block is site-wide). The quoted reproduction itself did NOT replay — its numbers (1/3, and 17) came from another concurrent agent's seeded fixtures, which were unseeded under me mid-measurement — but the defect reproduces exactly. First HTTP measurement while those fixtures existed, as a plain subscriber over the REST run route: curl ...query-jobs/run?input[company][]=182&input[per_page]=50 returned {"filters":{"include_closed":true,"order":"DESC","company":[182]},"total":6,"visibility":{"hidden_missing_featured":0,"hidden_expired":2}} with ids [187,184,189,188,186,185], while the two expired jobs site-wide were exactly 451 and 452, neither carrying company 182 (the only posts with job_company a:1:{i:0;s:3:"182";} are 184-189, all six returned). Controlled repro on the clean corpus, with one past job_expiry_date added to job 189 and removed afterwards: search="Stelle 01" answers total=1, ids [184], visibility.hidden_expired=1 — job 189 does not match that search at all, so it is excluded by the filters too, making the field's own wording ("Published jobs excluded from this answer by the site visibility rule rather than by the filters", abilities.php:1840) false; the true value is 0. The unfiltered call in the same window answers total=5, hidden_expired=1, which is correct, so the block is right only when no filter narrows it. The charitable reading of "th

---

## [important] One corrupt job_type or job_work_type meta row is an uncaught fatal on the 6.9.4 floor — and it takes query-jobs down with no input at all

**Lens:** floor-and-throw

**Consequence:** A single postmeta row whose serialized value contains a non-scalar element — what a WPML base64 copy, an importer, a migration script or one UPDATE statement produces, and exactly the kind of value CLAUDE.md already warns WPML can corrupt — makes `jpkcom-acf-jobs/query-jobs` fatal for EVERY caller with no input at all, permanently, as soon as that job is on the requested page. It is not confined to a filtered call: I measured page 1 answering 200 and page 3 answering 500 on a mixed corpus, so a client paginating walks into it. On the declared 6.9.4 floor it is an uncaught fatal — a blank 500, no body, no error code, nothing an agent can act on — triggerable by any logged-in subscriber. `list-filters` stays 200 throughout, so the site looks healthy from the discovery call. Note the asymmetry the code itself creates: `jpkcom_acf_jobs_normalise_choices()` is written to accept the bare-string shape as a first-class contract, so the value is only read formatted to obtain labels — and it is the formatted read that reaches `acf_maybe_get( $field['choices'], $value )` with a non-scalar offset.

**Reproduction:**

```
Fixture (what an importer or direct SQL writes — NOT reachable through update_field(), which sanitises it):

  update_post_meta( $id, 'job_type', [ [ 'FULL_TIME' ] ] );
  update_post_meta( $id, '_job_type', 'field_68de7a25cd78d' );
  update_post_meta( $id, 'job_featured', 1 );

Run through the ability layer of a checksum-verified WP 6.9.4 core (`wp core download --version=6.9.4 --path=/tmp/wp694`, `md5 hash verified: 02c249c8cd46e5e7505b631a9141fe25`) bootstrapped read-only against the live test2 database and wp-content, current user = a subscriber:

  $ ddev exec bash -c 'FF_ID=349 FF_UID=2 wp --path=/tmp/wp694 eval-file /tmp/wp694/ff694-probe.php'
  wp_version = 6.9.4
  class-wp-ability.php md5 = c15e2431083c3d1773493e3cdfd57983
  target job = 349 : FFLENS one bad meta row
  stored job_type = array (  0 =>   array (    0 => 'FULL_TIME',  ),)
  direct callback THROWS TypeError: Cannot access offset of type array in isset or empty
  current_user_can(read) = true
  about to call WP_Ability::execute() on core 6.9.4 ...
  PHP Fatal error:  Uncaught TypeError: Cannot access offset of type array in isset or empty in /var/www/html/wp-content/plugins/advanced-custom-fields-pro/includes/api/api-helpers.php:2566
  Stack trace:
  #0 .../class-acf-field-select.php(710): acf_maybe_get()
  #1 .../class-acf-field-select.php(685): acf_field_select->format_value_single()
  #2 .../class-acf-field-checkbox.php(562): acf_field_select->format_value()
  ...
  #10 /var/www/html/wp-content/plugins/jpkcom-acf-jobs/includes/jobs-data.php(711): get_field()
  #11 /var/www/html/wp-content/plugins/jpkcom-acf-jobs/includes/abilities.php(3424): jpkcom_acf_jobs_get_job_data()
  #12 /tmp/wp694/wp-includes/abilities-api/class-wp-ability.php(511): jpkcom_acf_jobs_ability_get_job()
  #13 /tmp/wp694/wp-includes/abilities-api/class-wp-ability.php(556): WP_Ability->invoke_callback()
  #14 /tmp/wp694/wp-includes/abilities-api/class-wp-ability.php(633): WP_Ability->do_execute()
  (exit status 255; the probe's trailing "SURVIVED" is never printed)

Same, for query-jobs called with NO input whatsoever:

  $ ddev exec bash -c 'wp --path=/tmp/wp694 eval-file /tmp/wp694/ff694-qj.php'
  wp_version = 6.9.4
  calling query-jobs with NO input on core 6.9.4 ...
  PHP Fatal error:  Uncaught TypeError: Cannot access offset of type array in isset or empty in .../api-helpers.php:2566

6.9.4's WP_Ability has no wrapper — verified against the downloaded release, not the installed tree:

  $ ddev exec bash -c 'gr
```

**Refuter's verdict:** Reproduced independently and end to end. Pristine checksum-verified WP 6.9.4 (wp core verify-checksums: Success; md5 c15e2431083c3d1773493e3cdfd57983) has zero try/catch/Throwable in class-wp-ability.php, confirming the floor has no wrapper. Bootstrapping that core against a COPY of the test2 DB, with current user a real subscriber and current_user_can(read)=true, WP_Ability::execute('jpkcom-acf-jobs/query-jobs', []) — NO input — dies with "PHP Fatal error: Uncaught TypeError: Cannot access offset of type array in isset or empty in .../api-helpers.php:2566", frame #10 jobs-data.php(711) get_field(), #11 abilities.php(3023), #12 class-wp-ability.php(511); the probe's trailing SURVIVED never prints. The shape map reproduces exactly (job_type: nested_array THROWS, arr_of_obj THROWS, object ok, assoc ok; job_work_type: object THROWS, nested_array ok). Over HTTP as a subscriber on the (now 7.0.2) test2: list-filters 200, query-jobs with no input 500 ability_callback_exception, get-job 500; per_page=1 page=1 500 / page=2 200 / page=3 200 confirms the pagination walk. No guard exists at any layer: grep for try/catch in includes/abilities.php returns only comments, the plugin registers no acf format filter, and jobs-data.php:711/712 sit in the COMPACT record so both abilities carry it. Not covered by any assertion and structurally uncoverable: tests/lib.php:417 defines its own get_field() stub reading $GLOBALS['jpkcom_test_fields'], so real ACF formatting never runs in the suite. Two facts the finding omits, both narrowing it. (1) The same corrupt row already 500s the PUBLIC front end — measured: with the row present /jobs/ = HTTP 500 and the single job page = HTTP 500; after deleting it the archive returns HTTP 200. templates/partials/job/job_type.php:15, templates/partials/archive/job_type.php:14 and includes/schema.php:75/174 all do the same formatted read. So this is pre-existing plugin-wide fragility, not something the 1.4.0 abilities work introduced, and the finding's

---

## [important] have_rows() on corrupt flexible-content meta fatals too — and the file's "read unformatted" discipline does not cover it, because this is the load path, not the format path

**Lens:** floor-and-throw

**Consequence:** `get-job` on such a job is an uncaught fatal on 6.9.4 (blank 500) for any subscriber. It matters separately from the job_type case because the frame is `acf_field_flexible_content->load_value()` — ACF's UNFORMATTED load — reached from `have_rows()` at jobs-data.php:911. The file's load-bearing rule ("every long-form field is read as get_field( $name, $post_id, false )") is about `format_value`, and it gives no protection here: `have_rows()` has no formatted/unformatted argument at all. Any hardening aimed only at the third argument of get_field() would leave this open.

**Reproduction:**

```
  update_post_meta( $id, 'job_layout_content', [ [ 'x' ] ] );        // or [ (object) [ 'x' => 1 ] ]
  update_post_meta( $id, '_job_layout_content', 'field_68dd0a4114356' );

  $ ddev exec bash -c 'FT_ID=304 wp eval-file floor-trace.php'
  TypeError: Cannot access offset of type array in isset or empty
  #0 /var/www/html/wp-includes/class-wp-hook.php(341): acf_field_flexible_content->load_value()
  ...
  #5 .../acf-value-functions.php(146): apply_filters()
  #6 .../api-template.php(255): acf_get_value()
  #7 .../api-template.php(606): get_field_object()
  #8 /var/www/html/wp-content/plugins/jpkcom-acf-jobs/includes/jobs-data.php(911): have_rows()
  #9 /var/www/html/wp-content/plugins/jpkcom-acf-jobs/includes/abilities.php(3424): jpkcom_acf_jobs_get_job_data()

From the per-field sweep:
  job_layout_content  nested_array [304] THROWABLE TypeError: Cannot access offset of type array in isset or empty
  job_layout_content  arr_of_obj   [306] THROWABLE TypeError: Cannot access offset of type stdClass in isset or empty
  job_layout_content  object [305] ok    assoc [307] ok

Over HTTP as a subscriber:
  $ curl -sk -g -u "ffsub:$PW" -o /dev/null -w "HTTP %{http_code}\n" ".../get-job/run?input[id]=304"
  HTTP 500
  $ ... input[id]=306  ->  HTTP 500
```

**Refuter's verdict:** I tried to refute this and could not. It reproduces frame-for-frame under my own hands, on the committed tree. But its severity is inflated, and two pieces of its own evidence are wrong (one in its favour, one against).

== 0. Tree and instances ==
`git rev-parse HEAD` = 5931479, not the 181f56f named in the brief; `git diff --stat 181f56f HEAD` = CLAUDE.md, README.md, jpkcom-acf-jobs.php (version bump), phpdoc.xml only — includes/ is byte-identical, so the finding's line numbers still apply. `diff -rq --exclude=.git ... /home/jpk/ddev/test2/wp-content/plugins/jpkcom-acf-jobs/` printed nothing: the instance already held this exact tree.

ENVIRONMENT DRIFT, not mine: /home/jpk/ddev/test2/wp-includes/version.php line 19 says `$wp_version = '7.0.2'`, NOT the declared floor 6.9.4. I did not change it (I never ran a core command). So the floor cannot be measured directly on the instance the brief names for it. Also, a parallel session is live on both instances: mid-run it deleted user `rfsub` out from under my authenticated curl (401), created `rfsal`/fixtures 424, 427 on test2, and left jobs 445-471 ("CORRUPT: ...") on /home/jpk/ddev/posts. One of my measurements was contaminated by it (see §4).

== 1. The reproduction reproduces ==
Fixture: a fresh published job (ID 422) on test2, `job_featured` row present, no job_url, no expiry — i.e. a job whose detail block is emitted.

$ ddev exec bash -c 'FT_ID=422 FT_CASE=nested_array wp eval-file zz-refute-probe.php'
nested_array  THROWABLE TypeError: Cannot access offset of type array in isset or empty
   #0 /var/www/html/wp-includes/class-wp-hook.php(341): acf_field_flexible_content->load_value()
   ...
   #6 .../includes/api/api-template.php(255): acf_get_value()
   #7 .../includes/api/api-template.php(606): get_field_object()
   #8 /var/www/html/wp-content/plugins/jpkcom-acf-jobs/includes/jobs-data.php(911): have_rows()

Identical for FT_CASE=arr_of_obj ("...of type stdClass..."). The throw site is ACF 6.8.6 pro/fields/clas

---

## [important] /home/jpk/ddev/test2 stopped being the 6.9.4 floor at 18:50:36, mid-run, from outside this task

**Lens:** floor-and-throw

**Consequence:** Every lens pointed at test2 after 18:50:37 is measuring WordPress 7.0.2 while believing it is on the declared 6.9.4 floor, and the two differ in exactly the way the whole no-throw rule is about: 6.9.4's `WP_Ability::invoke_callback()` is a bare `return $callback( ...$args );` and lets a Throwable become an uncaught fatal, while 7.0.2 wraps it and returns `WP_Error('ability_callback_exception')`. I left the core version alone as instructed and worked around it by bootstrapping a checksum-verified 6.9.4 tree read-only against the same database, which is how the fatals above were established. The orchestrator should decide whether to restore 6.9.4 there; I did not, because the instruction is absolute and another agent is live on that box.

**Reproduction:**

```
At the start of my run:
  $ cd /home/jpk/ddev/test2 && ddev wp core version
  6.9.4
  $ command grep -n "try\|catch\|Throwable" wp-includes/abilities-api/class-wp-ability.php
  15: * Encapsulates ...   19: * @see WP_Abilities_Registry   196: ... 200: ...   <- only 'try' inside "registry"; no catch, no Throwable
  $ command grep -n "function execute" -A 40 ...   ->  public function execute( $input = null ) {  at line 598

Minutes later, same file, same instance:
  $ ddev wp core version
  7.0.2
  $ command sed -n '513,515p' wp-includes/abilities-api/class-wp-ability.php
  		try {
  			return $callback( ...$args );
  		} catch ( Throwable $e ) {
  $ ls -la --time-style=full-iso wp-includes/version.php wp-includes/abilities-api/class-wp-ability.php wp-settings.php
  -rw-r--r-- 1 jpk jpk 23430 2026-08-04 18:50:36.508095618 +0200 wp-includes/abilities-api/class-wp-ability.php
  -rw-r--r-- 1 jpk jpk  1140 2026-08-04 18:50:37.368417412 +0200 wp-includes/version.php
  -rw-r--r-- 1 jpk jpk 32650 2026-08-04 18:50:37.367949131 +0200 wp-settings.php
  $ command grep -n "wp_version =" wp-includes/version.php
  19:$wp_version = '7.0.2';
  $ ddev wp option get db_version
  61833
  $ ddev exec md5sum /tmp/wp694/wp-includes/abilities-api/class-wp-ability.php /var/www/html/wp-includes/abilities-api/class-wp-ability.php
  c15e2431083c3d1773493e3cdfd57983  /tmp/wp694/...   (genuine 6.9.4)
  d56a888edd0150a3d13f8d58b8749322  /var/www/html/...

The symptom while it happened, on a wp-cli call issued seconds after my plugin rsync:
  PHP Fatal error:  Uncaught Error: Call to undefined function wp_get_view_transitions_admin_css() in /var/www/html/wp-includes/script-loader.php:1754
(core files half-replaced under a running request). My only write to that instance was `rsync … /home/jpk/ddev/test2/wp-content/plugins/jpkcom-acf-jobs/`, which cannot touch wp-includes. Other agents are demonstrably active on both instances: jobs titled WIRELENS/NUMLENS/LENSJOB appeared on both boxes during my run, and /home/jpk/wp/jpkcom-acf-jobs moved from 181f56f to 5931479 ("Release 1.4.0: Abilities API") while I measured — `git diff --stat 181f56f..HEAD -- includes/` is empty, so the two files I measured are byte-identical to what I measured.
```

**Refuter's verdict:** I tried to refute this and could not; it reproduces exactly, and three independent lines of evidence corroborate it beyond the quoted output.

1) THE REPRODUCTION REPRODUCES, byte-for-byte and nanosecond-for-nanosecond.
$ ls -la --time-style=full-iso /home/jpk/ddev/test2/wp-includes/version.php /home/jpk/ddev/test2/wp-includes/abilities-api/class-wp-ability.php /home/jpk/ddev/test2/wp-settings.php
-rw-r--r-- 1 jpk jpk 23430 2026-08-04 18:50:36.508095618 +0200 .../abilities-api/class-wp-ability.php
-rw-r--r-- 1 jpk jpk  1140 2026-08-04 18:50:37.368417412 +0200 .../version.php
-rw-r--r-- 1 jpk jpk 32650 2026-08-04 18:50:37.367949131 +0200 .../wp-settings.php
$ command grep -n "wp_version = |db_version = " .../test2/wp-includes/version.php
19:$wp_version = '7.0.2'
26:$wp_db_version = 61833
$ cd /home/jpk/ddev/test2 && ddev wp core version  ->  7.0.2
$ ddev wp option get db_version              ->  61833
$ md5sum test2 .../class-wp-ability.php      ->  d56a888edd0150a3d13f8d58b8749322
$ md5sum posts  .../class-wp-ability.php     ->  d56a888edd0150a3d13f8d58b8749322   (identical to the 7.0.2 box)
$ ddev exec 'command grep -n wp_version /tmp/wp694/wp-includes/version.php; md5sum /tmp/wp694/.../class-wp-ability.php'
19:$wp_version = '6.9.4'
c15e2431083c3d1773493e3cdfd57983   (the genuine-6.9.4 hash the finding quoted)
The declared floor instance is running 7.0.2 right now.

2) THE CONSEQUENCE IS REAL AND IS EXACTLY ON THE AUDIT'S AXIS.
$ command sed -n '505,525p' test2/wp-includes/abilities-api/class-wp-ability.php
  protected function invoke_callback( callable $callback, $input = null ) { ... try { return $callback( ...$args ); } catch ( Throwable $e ) { return new WP_Error( 'ability_callback_exception', ... ) } }
$ ddev exec 'command grep -c "Throwable" /tmp/wp694/wp-includes/abilities-api/class-wp-ability.php'
0
$ ddev exec 'command grep -n -A 30 "public function execute" /tmp/wp694/...'
598: public function execute( $input = null ) { ... }   (no try/catch anywhere in t

---

## [important] include_closed can never be sent over REST: GET is the only allowed method, GET cannot carry a JSON boolean, and the callback demands a strict bool

**Lens:** consumers

**Consequence:** The only switch the ability offers that changes which jobs come back is unusable through the entire REST surface. A REST agent that reads the schema and sends the declared default `include_closed: true` gets a 400; there is no value it can send that does not. The error text says "has to be a boolean" — it names a form the caller has no way to produce over the only method the route accepts, so the agent's correction loop cannot terminate: it will retry `true`, `"true"`, `1`, `on` and exhaust its budget. The two consumers also silently disagree about what the ability can do — an MCP client filters out filled positions, a REST client cannot — which breaks the premise that they expose the same surface.

**Reproduction:**

```
$ R="https://posts.ddev.site/wp-json/wp-abilities/v1/abilities"
$ for v in true false 1 0; do curl -g -sk -u "$S" -w 'HTTP %{http_code} ' \
    "$R/jpkcom-acf-jobs/query-jobs/run?input[include_closed]=$v"; done

  input[include_closed]=true   HTTP 400 jpkcom_acf_jobs_invalid_input | The "include_closed" parameter has to be a boolean. It defaults to true, matching the site itself...
  input[include_closed]=false  HTTP 400 jpkcom_acf_jobs_invalid_input | (same)
  input[include_closed]=1      HTTP 400 jpkcom_acf_jobs_invalid_input | (same)
  input[include_closed]=0      HTTP 400 jpkcom_acf_jobs_invalid_input | (same)

The method that could carry a real boolean is refused:
  $ curl -g -sk -u "$S" -X POST -H 'Content-Type: application/json' \
      -d '{"input":{"include_closed":false}}' -w 'HTTP %{http_code} ' "$R/jpkcom-acf-jobs/query-jobs/run"
  HTTP 405 rest_ability_invalid_method | Schreibgeschützte Fähigkeiten erfordern die GET-Methode.

A JSON body on GET is silently ignored (the default wins):
  $ curl -g -sk -u "$S" -X GET -H 'Content-Type: application/json' \
      -d '{"input":{"include_closed":false}}' "$R/jpkcom-acf-jobs/query-jobs/run"
  {"filters":{"include_closed":true,"order":"DESC"},...}

Mechanism, from core on the instance:
  class-wp-rest-abilities-v1-run-controller.php:193  get_input_from_request()
      -> $request->get_query_params()['input']        # raw strings, GET branch
  class-wp-ability.php:482  rest_validate_value_from_schema( $input, ... )
Core validates and never sanitises, and rest_is_boolean('false') is true, so the string passes validation and reaches the callback as a string.

The same parameter over MCP, which carries real JSON:
  {"method":"tools/call","params":{"name":"mcp-adapter-execute-ability","arguments":{"ability_name":"jpkcom-acf-jobs/query-jobs","parameters":{"include_closed":false}}}}
  -> {"success":true,"data":{"filters":{"include_closed":false,"order":"DESC"},...}}
Integer parameters are unaffected because the callback coerces them: input[per_page]=5 -> per_page 5, input[page]=2 -> page 2.
```

**Refuter's verdict:** Reproduced verbatim on /home/jpk/ddev/posts against the rsynced committed tree: input[include_closed]=true|false|1|0 all return HTTP 400 jpkcom_acf_jobs_invalid_input; POST returns 405 rest_ability_invalid_method; a JSON body on GET is ignored (filters.include_closed stays true); -G --data-urlencode 'input={"include_closed":false}' returns 400 "input ist nicht vom Typ object"; X-HTTP-Method-Override/_method=GET makes the method GET so the body is discarded again; and on/yes/[] are rejected by core, TRUE by the plugin. Mechanism confirmed in core on the instance: run-controller.php:193 takes get_query_params()['input'] for GET, class-wp-ability.php:444 normalize_input only substitutes a top-level default for exactly-null input, and :482 validates with rest_validate_value_from_schema and never sanitises, so rest_is_boolean('false') passes the string through to includes/abilities.php:2538 is_bool(), which rejects it. include_closed is the only strictly-typed parameter in the callback; page, per_page, order, search and the four axes are all string-tolerant. The consumer divergence is real and measured: over the Rank Math MCP adapter, parameters {"include_closed":false,"company":[182]} returns success:true with filters.include_closed=false. It is not covered by any assertion — `grep -n "rest_do_request\|WP_REST_Request\|wp-abilities/v1\|/run" tests/*.php` returns nothing, and every include_closed test (test-abilities.php:921,1043,1221,2258,2265) calls the callback in-process with a native PHP false. But severity is inflated. The consequence paragraph's central claim, "the only switch the ability offers that changes which jobs come back is unusable", is measurably false: over the same GET boundary company[]=182, location[]=183, attribute[]=vollzeit, search=Test, order=ASC, per_page=2 and job_type[]=FULL_TIME all reach the query and narrow correctly. The failure direction is also the safe one — a refusal, not the silent-dropped-clause class the feature exists to prevent; f

---

## [important] list-filters ships an input_schema that is not a valid JSON Schema — "properties": [] — to both consumers raw

**Lens:** consumers

**Consequence:** The plugin's own `jpkcom_acf_jobs_ability_json_object()` helper exists for precisely this hazard — its docblock names the MCP adapter as the reason — and it was applied to `default`, the one key core already repairs, and not to `properties`, the one key only the plugin can repair. The result is an ability whose advertised input contract is invalid JSON Schema in every consumer. Any client that validates a tool's inputSchema before registering it — the metaschema check above is the same one the major LLM tool-calling APIs apply — rejects `list-filters`, and a client that rejects the whole tools/list on one bad entry loses `query-jobs` and `get-job` with it. The ability's own description tells the model to "Call this before jpkcom-acf-jobs/query-jobs so no filter value ever has to be guessed", so the tool that is unreachable is the one the other two depend on for their vocabulary.

**Reproduction:**

```
Over core REST (both the list route and the single-ability route):
  $ curl -g -sk -u "$S" "https://posts.ddev.site/wp-json/wp-abilities/v1/abilities/jpkcom-acf-jobs/list-filters" | jq -c .input_schema
  {"type":"object","default":{},"properties":[]}

Over MCP, via the adapter's own get-ability-info tool:
  {"method":"tools/call","params":{"name":"mcp-adapter-get-ability-info","arguments":{"ability_name":"jpkcom-acf-jobs/list-filters"}}}
  -> structuredContent.input_schema = {"type":"object","default":{},"properties":[]}

And as an MCP tool's inputSchema, the form a client validates before offering the tool:
  $ ddev wp eval-file t-lens.php    # RegisterAbilityAsMcpTool::make($ability,$server)->to_array()
  jpkcom-acf-jobs/list-filters inputSchema: {"type":"object","default":{},"properties":[]}
  jpkcom-acf-jobs/query-jobs   inputSchema: {"type":"object","default":{},"properties":{"job_type":...}}
  jpkcom-acf-jobs/get-job      inputSchema: {"type":"object","required":["id"],"properties":{"id":...}}

It is rejected by the metaschema:
  $ python3 -c "from jsonschema import Draft202012Validator as V; V.check_schema(s)"
  jpkcom-acf-jobs/list-filters  input_schema  2020-12  SCHEMA-INVALID: [] is not of type 'object'
  jpkcom-acf-jobs/list-filters  input_schema  draft7   SCHEMA-INVALID: [] is not of type 'object'
  (all five other schemas: OK)
Identical on the second instance.

Why only this key leaks — core normalises exactly one:
  wp-includes/rest-api/endpoints/class-wp-rest-abilities-v1-list-controller.php:208
    private function normalize_schema_empty_object_defaults( array $schema ): array {
      if ( isset($schema['type']) && 'object' === $schema['type'] && isset($schema['default']) ) {
        if ( is_array($default) && empty($default) ) { $schema['default'] = (object) $default; }
No equivalent exists for `properties`, and the MCP adapter normalises nothing.
```

**Refuter's verdict:** Reproduced exactly, independently, on both instances. Source: includes/abilities.php:1642 registers 'properties' => [] for list-filters. Live REST as a plain subscriber, both the single-ability and the list route, returns {"type":"object","default":{},"properties":[]}; query-jobs and get-job are clean. Metaschema check over all six schemas dumped from the live registry: only list-filters/input_schema fails, under both 2020-12 and draft7 ("[] is not of type 'object'"), identical on /home/jpk/ddev/test2. Core repairs only 'default' (class-wp-rest-abilities-v1-list-controller.php:208, normalize_schema_empty_object_defaults), never 'properties'. Confirmed over the live MCP endpoint too: tools/call mcp-adapter-get-ability-info returns structuredContent.input_schema with properties:[]; all three abilities are discoverable there. I searched for the guard that would make it moot and none exists: WP_Ability::prepare_properties only checks is_array(); SchemaTransformer returns a type:object schema untouched; McpToolValidator only requires is_array($schema['properties']), which [] satisfies. I also found a consumer the finding missed, which strengthens it: core's own wp-includes/ai-client/class-wp-ai-client-prompt-builder.php:264-270 (using_abilities) feeds get_input_schema() straight into a FunctionDeclaration, whose toArray()['parameters'] is the provider tool-declaration payload and which validates nothing inside it — measured output "parameters = []". Not covered by any assertion: tests/test-abilities.php:107-112 and :556-561 assert this exact defect class for 'default' and for the runtime 'unknown' map, and the suite runs 453 passed, 0 failed with the defect present. ONE CLAUSE OF THE CONSEQUENCE IS OVERSTATED: the shipped stack never builds these abilities into MCP tools — tools/list returns only mcp-adapter-discover-abilities, get-ability-info and execute-ability, because DefaultServerFactory.php:52-56 hardcodes those three and discover_abilities_by_type() auto-discover

---

## [important] search is documented as covering "job content" but reaches none of the content the ability itself returns, and the empty result is dressed as authoritative

**Lens:** consumers

**Consequence:** `search` is WP_Query's `s`, which reads post_title / post_excerpt / post_content. Every field this plugin actually holds job text in — `summary`, `detail.layout[].text`, `detail.application.description`, company and location names — lives in ACF meta, and `post_content` is empty on the plugin's own fixtures, so "job content" describes a corpus that does not exist. A model asked "which of these jobs mentions a company car" sends `search=Firmenwagen`, receives `total: 0` with `unknown: {}`, and the `unknown` description tells it exactly how to read that — "Not an error: the empty result is honest, and this tells a typo apart from a genuinely empty result set." It will report to the user that no such job exists. Four do. The one phrase that would have prevented the wrong answer, that search covers only titles, is the phrase the description replaces with a broader claim.

**Reproduction:**

```
The schema says: in.search — "Free-text search across job titles and job content."

$ curl -g -sk -u "$S" ".../get-job/run?input[id]=184"
  get-job 184 summary = 'Kurzbeschreibung zu Stelle 01.'

$ curl -g -sk -u "$S" ".../query-jobs/run?input[search]=Kurzbeschreibung"
  total=0 unknown={}

$ curl -g -sk -u "$S" ".../query-jobs/run?input[search]=Firmenwagen"
  total=0 unknown={}
$ curl -g -sk -u "$S" ".../query-jobs/run?input[attribute][]=firmenwagen"
  total=4

$ curl -g -sk -u "$S" ".../query-jobs/run?input[search]=Stelle"
  total=6            # matches only because "Stelle" is in post_title

$ ddev wp post get 184 --field=post_content
  (empty)

Also 0 for Testfirma (the company name the ability returns) and Teststadt (the location name it returns).
```

**Refuter's verdict:** Reproduced verbatim on /home/jpk/ddev/posts (WP 7.0.2) against the committed tree rsynced across. Route is /wp-json/wp-abilities/v1/abilities/... . get-job 184 returns summary="Kurzbeschreibung zu Stelle 01.", companies[].name="Testfirma GmbH", locations[].name="Teststadt", attributes[].name="Firmenwagen"; query-jobs with search=Kurzbeschreibung / Firmenwagen / Testfirma / Teststadt each return total=0 unknown={} with filters echoing the term back, while input[attribute][]=firmenwagen returns total=4 (ids 187,184,189,186). search=Stelle returns 6, by post_title alone. The delivered schema string is exactly "Free-text search across job titles and job content." and the unknown block is exactly "Not an error: the empty result is honest...".

No guard at any layer. includes/jobs-data.php:81-83 is the whole application ($query_args['s'] = sanitize_text_field(...)); no posts_search/posts_where filter exists in the plugin; the ability-level description ("or free text") and jpkcom_acf_jobs_ability_meta() add no scoping. Live SQL dump (ddev wp eval, WP_Query s=Firmenwagen) shows s covers exactly post_title OR post_excerpt OR post_content.

The mechanism is worse than the finding states. The job post type declares no 'editor' support (includes/acf-post_types.php:168-175 = title, author, excerpt, revisions, thumbnail, custom-fields) and templates/single-job.php never calls the_content(), so post_content is unfillable and unrendered on every site by design, not empty by fixture accident. post_excerpt, the only other searched column, is never rendered or returned either (grep for the_excerpt/get_the_excerpt/post_excerpt over templates and includes returns nothing). DB check: all seven job rows have LENGTH(post_content)=0 and LENGTH(post_excerpt)=0. Every string the ability returns -- summary (get_field job_short_description, jobs-data.php:710), detail.layout[].text, detail.application.description, company/location titles, attribute term names -- lives in ACF meta or on other pos

---

## [important] An input axis the output wording advertises does not exist, and an unrecognised axis is swallowed with no signal at all

**Lens:** consumers

**Consequence:** The `filters` block correctly omits what it did not apply — that guarantee holds — but the caller is given nothing that says the axis was rejected: same HTTP 200, same total as an unfiltered call, `unknown: {}`. The model must notice an absence to notice the failure, and a model that trusts `total` reports the entire corpus as "7 remote-work jobs". The wording is what puts it there: "This is the only form accepted as filter input" on `work_type.value` is a direct instruction to send a `work_type` filter that does not exist. A single typo (`compnay`) has the same silent outcome. `additionalProperties: false` on the input schema would convert both into the 4xx the caller needs.

**Reproduction:**

```
The output schema of query-jobs and get-job, verbatim:
  out.jobs[].work_type       "Remote work, on-site work or both. Null when the job does not say."
  out.jobs[].work_type.value "Stable value. This is the only form accepted as filter input."
  out.jobs[].work_type.label "Human-readable label in the site language. Locale-dependent, and never valid as filter input."
The input schema of query-jobs has no work_type property.

$ R="https://posts.ddev.site/wp-json/wp-abilities/v1/abilities/jpkcom-acf-jobs/query-jobs/run"
$ curl -g -sk -u "$S" "$R"
  HTTP200 total=7   filters={"include_closed":true,"order":"DESC"} unknown={}
$ curl -g -sk -u "$S" "$R?input[work_type][]=TELECOMMUTE"
  HTTP200 total=7   filters={"include_closed":true,"order":"DESC"} unknown={}
$ curl -g -sk -u "$S" "$R?input[wurk_type][]=x"
  HTTP200 total=7   filters={"include_closed":true,"order":"DESC"} unknown={}
$ curl -g -sk -u "$S" "$R?input[compnay][]=182"
  HTTP200 total=7   filters={"include_closed":true,"order":"DESC"} unknown={}

Neither input_schema declares additionalProperties:
  jpkcom-acf-jobs/query-jobs  input: required=None additionalProperties=None

Related, same class: an unknown job_type value never reaches `unknown` because the enum rejects it at validation (400), so the axis the description says is covered "per axis" can never appear there.
```

**Refuter's verdict:** Reproduced verbatim on /home/jpk/ddev/posts against the rsynced committed tree. `curl -g -sk -u "$S" ".../query-jobs/run?input[work_type][]=TELECOMMUTE"` → HTTP200 total=6 filters={"include_closed":true,"order":"DESC"} unknown={}, identical to the unfiltered call; same for `input[wurk_type][]=x` and `input[compnay][]=182`. Live schemas confirm it: query-jobs input additionalProperties=None with props [job_type,company,location,attribute,search,include_closed,page,per_page,order] (no work_type), while get-job/query-jobs output carry "work_type.value: Stable value. This is the only form accepted as filter input." — the shared $choice_schema at includes/abilities.php:1494 reused for work_type at line 1601. One quoted number does NOT reproduce: total=7 vs the real corpus of 6 (184-189); the 7th was a concurrent agent's fixture post 547, present when I started. Structural claim unaffected.

I searched for the next-layer guard and found only partial ones. list-filters output has no work_type property, so a caller obeying "call list-filters first" would not invent the axis — a real mitigation the finding does not credit. But core cannot help: WP_Ability::execute() always calls validate_input() (test2 wp-includes/abilities-api/class-wp-ability.php:614), and I verified the mechanism directly — with 'additionalProperties'=>false, rest_validate_value_from_schema returns "rest_additional_properties_forbidden | wurk_type ist keine gültige Eigenschaft des Objekts."; without it, VALID. Every DECLARED axis is guarded correctly and this held: company[]=abc→400 "nicht vom Typ integer", per_page=999→400 range, page=0→400, input=5→400 "nicht vom Typ object", order=SIDEWAYS→400 enum, job_type[]=NOPE→400 naming all eight values, and company[]=999999→200 total 0 filters{"company":[]} unknown{"company":[999999]}. So the plugin signals loudly at the value level and not at all at the key level. The sub-claim about unknown['job_type'] also holds: the enum 400s first and both the schema enum (

---

## [minor] The page-size guard is one-directional: pre_get_posts SHRINKING the page, or setting offset, produces an internally contradictory page that neither check reads

**Lens:** verify-last-round

**Consequence:** All three answer HTTP 200. `shrink` returns a response whose own numbers cannot all be true — per_page 5, total 27, total_pages 14, two jobs — and a caller that walks 14 pages of 5 gets 2 jobs per page and never learns why; the arithmetic check total_pages === ceil(total/per_page) would have caught it and is not made. `offset` makes page 1 start at row 4: jobs 1-3 of the result set are unreachable through ANY page number while total still says 27 across 6 pages, so a client that paginates to completion silently loses rows — and both guards are satisfied (5 is not > 5, 27 is not < 5). `order_flip` executes ORDER BY post_title ASC while filters.order reports DESC, which also destroys the page-1/page-2 disjointness the ID tiebreaker exists to guarantee. The guard's own framing ("more rows than the reported page can hold, or a total smaller than the jobs beside it") is exactly its blind spot: fewer rows, a shifted window and a different sort are all invisible to it.

**Reproduction:**

```
cd /home/jpk/ddev/posts && ddev wp eval-file wp-content/plugins/jpkcom-acf-jobs/tools/zz-lens.php <case>   (one pre_get_posts mutation per process, input per_page=5, page=1)

== case: none ==
  total=27 per_page=5 total_pages=6 jobs=5   ids=[187,184,392,388,330]
  consistency: ceil(total/per_page)=6 vs total_pages=6

== case: shrink ==            ($q->set('posts_per_page', 2))
  total=27 per_page=5 total_pages=14 jobs=2  ids=[187,184]
  consistency: ceil(total/per_page)=6 vs total_pages=14
  SQL[0] tail: ... LIMIT 0, 2

== case: offset ==            ($q->set('offset', 3))
  total=27 per_page=5 total_pages=6 jobs=5   ids=[388,330,329,328,327]
  SQL[0] tail: ... LIMIT 3, 5

== case: order_flip ==        ($q->set('order','ASC'); $q->set('orderby','title'))
  total=27 per_page=5 total_pages=6 jobs=5   ids=[326,328,327,329,330]
  SQL[0] tail: ... ORDER BY jpkA11yTpl1_posts.post_title ASC LIMIT 0, 5
```

**Refuter's verdict:** HOLDS, but the severity is inflated and two of its sub-claims are overstated. I could not refute the core: the post-execution guard in includes/abilities.php:2962-2999 tests only `count($posts) > $per_page` and `$total < count($posts)`, so a pre_get_posts callback that SHRINKS the page or sets an OFFSET produces a 200 whose numbers are wrong, and nothing downstream catches it.

1) DOES IT REPRODUCE — YES, on /home/jpk/ddev/posts (WP 7.0.2), against the committed tree at branch abilities-api (working tree clean; note HEAD is 5931479 "Release 1.4.0: Abilities API", two commits past the 181f56f named in the brief). The lens' own tools/zz-lens.php is not committed, so I wrote my own harness at /home/jpk/ddev/posts/zz-refute-lens.php (since deleted): one pre_get_posts mutation per process, a posts_request tap for the SQL, direct call to jpkcom_acf_jobs_ability_query_jobs([per_page=>5,page=>N]). Corpus was 6 jobs, not the lens' 27, so the numbers differ; the shape is identical.

  cd /home/jpk/ddev/posts && for c in none shrink offset grow; do for pg in 1 2; do ddev wp eval-file zz-refute-lens.php $c $pg; done; done

  == none page 1 ==   total=6 per_page=5 page=1 total_pages=2 jobs=5 ids=[187,184,189,188,186]   consistency: ceil(total/per_page)=2 vs total_pages=2  OK
  == none page 2 ==   total=6 per_page=5 page=2 total_pages=2 jobs=1 ids=[185]
  == shrink page 1 == total=6 per_page=5 page=1 total_pages=3 jobs=2 ids=[187,184]   consistency: ceil=2 vs total_pages=3  MISMATCH   SQL ... LIMIT 0, 2
  == shrink page 2 == total=6 per_page=5 page=2 total_pages=3 jobs=2 ids=[189,188]   MISMATCH   SQL ... LIMIT 2, 2
  == offset page 1 == total=6 per_page=5 page=1 total_pages=2 jobs=3 ids=[188,186,185]   SQL ... LIMIT 3, 5
  == offset page 2 == total=6 per_page=5 page=2 total_pages=2 jobs=3 ids=[188,186,185]   SQL ... LIMIT 3, 5
  == grow page 1 ==   WP_Error jpkcom_acf_jobs_filter_not_applied status=500  (the guard fires)
  == grow page 2 ==   total=6 per_page=5 page=2 total_page

---

## [minor] An ADDED clause with a non-scalar compare — the one operation the filter's contract permits — makes core throw; the ability's guards tolerate the value precisely because they refuse to cast it

**Lens:** verify-last-round

**Consequence:** jpkcom_acf_jobs_ability_group_relation() checks is_scalar and returns 'AND' for a non-scalar relation, and jpkcom_acf_jobs_ability_unbacked_claim() checks is_scalar before casting a direction — both with the comment that a cast of a non-scalar throws and "a Throwable out of an ability callback is an uncaught fatal on the declared 6.9 floor". The guards then hand the same untouched value to WP_Meta_Query, which casts it at class-wp-meta-query.php:229 (relation) and :542 (compare). So the class the code is explicitly defending against is reintroduced one call later, through the channel the contract advertises as safe. On the 7.0.2 instances the abilities layer catches it (class-wp-ability.php:513) and answers 500 with the raw exception text rather than the designed jpkcom_acf_jobs_filter_not_applied message; on the declared 6.9.4 floor, which the brief states has no try/catch, the same Throwable is an uncaught fatal — a blank 500 with no body. I could not measure 6.9.4: /home/jpk/ddev/test2 now reports 7.0.2 (see the environment note).

**Reproduction:**

```
mu-plugin:
  add_filter('jpkcom_acf_jobs_ability_query_args', function($args,$input){
      $args['meta_query'][] = [ 'key'=>'job_closed', 'compare'=>['NOT EXISTS'] ];   // an ADDITION, nothing altered
      return $args;
  }, 10, 2);

curl -sk -g -u 'abilitysub:***' 'https://posts.ddev.site/wp-json/wp-abilities/v1/abilities/jpkcom-acf-jobs/query-jobs/run?input[per_page]=3'
{"code":"ability_callback_exception","message":"Der Callback der Fähigkeit „jpkcom-acf-jobs/query-jobs“ hat eine Ausnahme ausgelöst: strtoupper(): Argument #1 ($string) must be of type string, array given","data":null}
HTTP 500

In-process, with the throw caught at the call site to show where it comes from:
  == case: compare ==   THROWN TypeError: strtoupper(): Argument #1 ($string) must be of type string, array given
                        at /var/www/html/wp-includes/class-wp-meta-query.php:542
  == case: relation ==  ($args['meta_query']['relation'] = ['AND'])
                        THROWN TypeError: strtoupper(): ... array given
                        at /var/www/html/wp-includes/class-wp-meta-query.php:229
```

**Refuter's verdict:** HOLDS as mechanism, but the severity is inflated and the framing is overstated. Downgrade important -> minor.

## 1. Does it reproduce? Yes, verbatim, on the named instance against the committed tree.

Rsynced HEAD 181f56f into /home/jpk/ddev/posts (branch abilities-api, `git status` clean). Created my own subscriber (user 11 `refutesub`, app password uuid cd77d857-599d-45b8-bbcc-92556e50f2c7) because another lens had deleted user 10 mid-session. mu-plugin /home/jpk/ddev/posts/wp-content/mu-plugins/zz-refute-lens.php, gated on ?jpkrefute=, adding exactly the claimed clause.

Control (no mode):
`curl -sk -g -u 'refutesub:***' 'https://posts.ddev.site/wp-json/wp-abilities/v1/abilities/jpkcom-acf-jobs/query-jobs/run?input[per_page]=3'`
-> `{"filters":{"include_closed":true,"order":"DESC"},...,"total":6,...}` HTTP 200

compare case (`$args['meta_query'][] = ['key'=>'job_closed','compare'=>['NOT EXISTS']]`):
`curl -sk -g -u 'refutesub:***' 'https://.../query-jobs/run?input[per_page]=3&jpkrefute=compare'`
-> `{"code":"ability_callback_exception","message":"Der Callback der Fähigkeit „jpkcom-acf-jobs/query-jobs“ hat eine Ausnahme ausgelöst: strtoupper(): Argument #1 ($string) must be of type string, array given","data":null}` HTTP 500

relation case (`$args['meta_query']['relation'] = ['AND']`): byte-identical body, HTTP 500.

In-process, throw caught at the call site (`ddev exec wp eval-file wp-content/refute-trace.php`, mode from a file):
```
== mode: none ==            OK total=6 filters={"include_closed":true,"order":"DESC"}
== mode: compare ==         THROWN TypeError: strtoupper(): ... array given
                              at /var/www/html/wp-includes/class-wp-meta-query.php:542
                              #1 :469 get_sql_for_clause  #5 class-wp-query.php:2477 get_sql
== mode: relation ==        THROWN TypeError: strtoupper(): ... array given
                              at /var/www/html/wp-includes/class-wp-meta-query.php:229
                              #1 

---

## [minor] Disclosed concern confirmed: pre_get_posts widening the corpus inflates total past what the reader's gate will emit, and nothing reconciles the two

**Lens:** verify-last-round

**Consequence:** The gate itself held — the draft, private and password-protected rows were dropped, nothing leaked — but the answer is still wrong in a way no guard reads: a page of per_page 5 comes back with 4 jobs beside total 29 and total_pages 6, so a caller cannot distinguish "last page" from "rows were withheld", and paging to the end yields fewer jobs than total promised with no signal. The ptype case shows the same hole in the visibility block, which is computed by two further WP_Query calls that no guard inspects at all: hidden_missing_featured jumps to 4 counting pages rather than jobs, next to total 0 and an empty job list. A cheap reconciliation exists and is not made: on a full page, count(jobs) < count(posts) means rows were withheld, and total then describes a corpus the response is not reporting.

**Reproduction:**

```
cd /home/jpk/ddev/posts && ddev wp eval-file wp-content/plugins/jpkcom-acf-jobs/tools/zz-lens.php <case>   (input per_page=5, page=1)

== case: none ==      total=27 per_page=5 total_pages=6 jobs=5 visibility={"hidden_missing_featured":0,"hidden_expired":16}
== case: status ==    ($q->set('post_status',['publish','draft','private']))
  total=29 per_page=5 total_pages=6 jobs=4 visibility={"hidden_missing_featured":0,"hidden_expired":16}
  ids=[187,184,392,388]
  SQL[0] tail: ...post_status = 'private')) GROUP BY ... LIMIT 0, 5
== case: password ==  ($q->set('has_password', null))
  total=29 per_page=5 total_pages=6 jobs=4   ids=[187,184,392,388]
== case: ptype ==     ($q->set('post_type','page'))
  total=0 per_page=5 total_pages=0 jobs=0 visibility={"hidden_missing_featured":4,"hidden_expired":0}
```

**Refuter's verdict:** Reproduced independently on a clean instance (/home/jpk/ddev/test2) against the committed tree; the quoted repro itself is unrunnable (tools/zz-lens.php does not exist) and /home/jpk/ddev/posts is currently contaminated by another agent's mu-plugin that forces posts_per_page=2 on job archive queries, so the finding's own numbers are unreliable. My own lens: with a pre_get_posts that widens post_status for post_type=job, the ability answered total=10 page=1 per_page=5 total_pages=2 jobs=3 while WP_Query returned 5 rows; with has_password nulled, total=9 jobs=4 beside 5 rows. Neither branch of the overrun guard at includes/abilities.php:2963-2979 fires (count($posts)==per_page, total>=count($posts)), and the reader gate drops the rows silently at :3028 in a continue whose own comment names this case. The earlier unbacked_claim and query_divergence guards run on $args before new WP_Query, so a pre_get_posts mutation is invisible to them. Control case (posts_per_archive_page=50) does trip the guard with a 500, proving it is live but one-sided. output_schema:1818 declares total as "Number of jobs matching the filters" with no hedge, so the contract really is broken. The visibility block is likewise unguarded: with post_type flipped to page, hidden_missing_featured=2 equals the count of published pages. What held: the gate never leaked a draft, private or password-protected job in any case, and no caller input can open the gap since the ability's own args already satisfy the gate. Severity minor is correct, not inflated: it needs a site pre_get_posts callback to misbehave, discloses nothing, and is partly self-evident (a short page below total_pages), so the finding's "no signal" framing is slightly overstated - but it is not a mere note, since it is a gap in the exact guard family the code built for pre_get_posts, and tests/test-abilities.php:1675-1702 covers only over-delivery and no_found_rows, not corpus widening.

---

## [minor] job_location_place — the addressLocality half of the postal address — is emitted OUTSIDE the detail gate by all three abilities, for jobs whose detail page never renders and for locations no listed job references

**Lens:** disclosure

**Consequence:** Any logged-in subscriber — the default `read` capability — obtains the city of every job_location the site holds, including (a) locations whose full address the detail gate exists to withhold, (b) locations belonging to expired jobs, which appear in no listing and whose pages 307 away, (c) locations belonging to jobs with job_url set, whose pages 307 to an external target, and (d) locations that no listed job references at all, which the list shortcode can therefore never render. The stated rule is that a job with no public render path must not have its postal address appear; job_location_place is the addressLocality component of that address (includes/schema.php:163) and it appears. The record contradicts itself: the identical value is withheld under detail.locations[].place and shipped under locations[].place in the same response. Nothing else on the site publishes it — the archive renders only the location title, the location's own page 302s for non-editors, and core REST returns "acf":[].

**Reproduction:**

```
Fixture on /home/jpk/ddev/posts: published job_location 385 'LENSORPHANLOC' with job_location_place='LENSORPHANPLACE', job_location_street='LENSORPHANSTREET 1', zip '07777'; published job 386 'LENSORPHANJOB' referencing it, job_expiry_date='20250101' (expired), salary 555555.

1) The site publishes none of it. Anonymous:

  $ curl -s -k -o /dev/null -w '%{http_code} -> %{redirect_url}\n' 'https://posts.ddev.site/job/lensorphanjob/'
  307 -> https://posts.ddev.site/jobs/

  $ curl -s -k -o /dev/null -w '%{http_code} -> %{redirect_url}\n' 'https://posts.ddev.site/job_location/lensorphanloc/'
  302 -> https://posts.ddev.site/jobs/

  $ curl -s -k 'https://posts.ddev.site/jobs/' | grep -c 'LENSORPHANPLACE\|LENSREDIRPLACE\|LENSORPHANSTREET\|LENSREDIRSTREET'
  0

  (the public archive DOES render the location title — grep 'LENSLOCNAME' → 3, grep 'LENSREDIRLOC' → 1 — because templates/partials/archive/job_location.php echoes get_the_title($location->ID) and nothing else. job_location_place is rendered in exactly one template, templates/partials/job/job_location.php, i.e. the single-job detail page, and fed to schema.php:163 as addressLocality of the JobPosting JSON-LD on that same page. templates/shortcodes/list.php:47 also renders it, but only for LISTED jobs.)

2) Core REST does not expose it to the same subscriber:

  $ curl -s -k -u 'abilitysub:<app-pw>' 'https://posts.ddev.site/wp-json/wp/v2/job_location/385'
  {"id":385,…,"title":{"rendered":"LENSORPHANLOC"},"template":"","acf":[],"_links":…

3) The ability publishes it. Same subscriber:

  $ curl -s -k -g -u 'abilitysub:<app-pw>' 'https://posts.ddev.site/wp-json/wp-abilities/v1/abilities/jpkcom-acf-jobs/get-job/run?input[id]=386'
  {
   "id": 386,
   "title": "LENSORPHANJOB",
   "locations": [ { "id": 385, "name": "LENSORPHANLOC", "place": "LENSORPHANPLACE" } ],
   "listed": false,
   "listed_reason": "expired",
   "detail_omitted_reason": "expired"
  }
  detail present: False

  $ curl -s -k -g -u 'abilitysub:<app-pw>' '…/get-job/run?input[id]=388'   # job_url set, page 307s to example.com
  {
   "id": 388,
   "title": "LENSREDIRJOB",
   "locations": [ { "id": 387, "name": "LENSREDIRLOC", "place": "LENSREDIRPLACE" } ],
   "redirects_externally": true,
   "detail_omitted_reason": "redirects_externally"
  }
  detail present: False

  $ curl -s -k -g -u 'abilitysub:<app-pw>' '…/list-filters/run'   # locations block
  [ { "id": 285, "name": "LENSLOCNAME", "place": "LENSPLACE", "count": 14 },
    { "id": 385, 
```

**Refuter's verdict:** Reproduced end to end on /home/jpk/ddev/posts against the committed tree, and again on /home/jpk/ddev/test2. As subscriber reflenssub: get-job on expired job 437 returns "locations":[{"id":436,"name":"REFORPHANLOC","place":"REFORPHANPLACE"}] with "detail_omitted_reason":"expired" and no detail block; get-job on job_url job 439 returns "place":"REFREDIRPLACE" with "detail_omitted_reason":"redirects_externally"; list-filters returns {"id":436,"name":"REFORPHANLOC","place":"REFORPHANPLACE","count":0}. Code confirmed: jobs-data.php:655 reads job_location_place into the compact record before the !$full return at :719 and both gate returns at :761/:769, re-emitting it at :806 inside the gate; abilities.php:2222 does it unconditionally for the whole vocabulary. I hunted the guard and there is none for the residual cases. Anonymous surfaces: job pages 307, location pages 302, /jobs/ grep for REFORPHANPLACE/REFREDIRPLACE/REFORPHANSTREET = 0 (the archive partial echoes only get_the_title), archive-job_location.php is unreachable because job_location has no has_archive, and core REST returns "acf":[] since all five ACF groups carry show_in_rest => 0. README:120-123 promises the postal address is withheld for jobs with no public detail page and schema.php:163 is the plugin's own statement that place is addressLocality, so the contradiction is real for locations attached only to expired jobs and for locations no listed job references.

But the claimed consequence is materially overstated, so severity is minor, not important. (1) The job_url case is already anonymously public: such a job is listed ("listed":true on 439), and the plugin's own shortcode prefers place over the title -- `ddev wp eval 'echo do_shortcode("[jpkcom_acf_jobs_list]");' | grep -c REFREDIRPLACE` returns 1. Claimed consequence (c) falls. (2) query-jobs adds no disclosure at all: it returns only listed jobs, and input[location][0]=436 returned "jobs":[],"total":0, so its place set is exactly the shortcode's. N

---

## [minor] query-jobs and list-filters silently omit password-protected jobs that the site itself lists publicly, and no field in either response says so

**Lens:** wrong-answers

**Consequence:** The ability is described as "Returns the jobs this site lists" and query-jobs' `total` as "Number of jobs matching the filters". The site lists 10 jobs on /jobs/ and 30 through [jpkcom_acf_jobs_list]; the ability answers 9 and 28. `published_total` reports 12 against 13 published jobs. There is no `hidden_password_protected` counter and nothing in `filters`, so the shortfall is invisible: an agent asked "is there an open position for X" can answer "no" while the site's own job page shows one, and an agent reconciling the ability against the site's own listing will find a discrepancy it cannot explain from the response. The exclusion itself is defensible (ACF ignores post passwords); reporting it is what is missing.

**Reproduction:**

```
Anonymous public archive, all three pages:

  $ for p in "" "page/2/" "page/3/"; do curl -sk "https://posts.ddev.site/jobs/$p" | command grep -o 'id="job-[0-9]*"' | sed 's/[^0-9]//g' | tr '\n' ' '; done
  187 184 400 397 389 392 185 186 188 189

  $ curl -sk "https://posts.ddev.site/jobs/" | ... (context around 389)
  <article id="job-389" class="jpkcom-acf-job--item card horizontal p-0 mb-4"> ... <a class="text-body text-decoration-none" href="https://posts.ddev.site/job/numlens-passwortjob/"> <h2 class="job-title h4">Geschützt: NUMLENS Passwortjob</h2> </a>

The abilities at the same moment:

  $ curl -skg -u "abilitysub:ErFkRzBH1zqq1VJGmZz4Hg5G" ".../jpkcom-acf-jobs/query-jobs/run?input[per_page]=50"
  total 9 ids [187, 184, 400, 397, 392, 189, 188, 186, 185]
  $ curl -skg -u "abilitysub:..." ".../jpkcom-acf-jobs/list-filters/run"
  {"published_total": 12, "listed_total": 9, "hidden_missing_featured": 1, "hidden_expired": 3}
  $ ddev wp eval '... COUNT(*) ... post_type="job" AND post_status="publish" ...'
  published jobs (all)      : 13
  of which password-protected: 1

The shortcode agrees with the archive, not with the ability:

  == [jpkcom_acf_jobs_list] shortcode, ids it renders ==
  shortcode ids (30): 184,...,324,...,389,392,397
  == ability query-jobs ==
  ability total=28 ids (28): 184,...,392,397
  only in shortcode: 324,389
  only in ability:   (none)

  $ ddev wp eval-file numlens-probe5.php   # archive-rule query, anonymous
  only on archive, not in ability: 324,389
     324  status=publish pw='LENSJOBPWD'  title=LENSJOB password
     389  status=publish pw='geheim'  title=NUMLENS Passwortjob
```

**Refuter's verdict:** The behaviour reproduces exactly, but the finding's central claim is refuted and its severity is inflated.

REPRODUCES. On /home/jpk/ddev/posts with the committed tree rsynced in, I created one published, non-expired, job_featured-carrying, password-protected job (id 472, pw 'geheim'). The anonymous archive lists it — `curl -sk "https://posts.ddev.site/jobs/" | grep -o 'id="job-[0-9]*"'` → `184 187 472 185 186 188`, markup `<h2 class="job-title h4">Geschützt: REFUTE Passwortjob</h2>` plus the ACF short description in the clear. The shortcode's own query includes it and the ability's does not, differing by exactly that job: `shortcode-rule ids (18): …,472,… / ability-rule ids (17): … / only in site listing: 472`. Live: `GET /wp-json/wp-abilities/v1/abilities/jpkcom-acf-jobs/query-jobs/run?input[per_page]=50` → total 17 against 18 the site lists, top-level keys ['archive_url','filters','jobs','language','page','per_page','total','total_pages','unknown','visibility'], visibility {hidden_missing_featured:1, hidden_expired:6}, and archive_url pointing at the page that shows 18. (The finding's quoted route /wp/v2/abilities/… is wrong; it answers rest_no_route. The real route is /wp-abilities/v1/.)

REFUTED — "no field in either response says so" and "the shortfall is invisible" are both false. list-filters' output_schema, fetched live from the registry, declares published_total as "Published jobs that are not password-protected." (includes/abilities.php:1712). Measured {"published_total":17,"listed_total":13,"hidden_missing_featured":1,"hidden_expired":3} against 18 published rows of which 1 has a password: 18−1=17 and 17−1−3=13, so the block's own claim that the two causes "partition the difference exactly" is true precisely because the password exclusion sits in an explicitly labelled denominator. There is no unexplained 12-vs-13. get-job discloses it in the error body: "…for one naming something other than a published, unprotected job…". Lines 1605/1610 say the same fo

---

## [minor] A site pre_get_posts can rewrite paged, the sort, or reduce posts_per_page, and neither of the two "read what came back" checks fires — page 1 union page 2 then repeats and omits while every page reports the same total

**Lens:** wrong-answers

**Consequence:** CLAUDE.md point 9 names pre_get_posts as the one route non-carriage cannot close and names exactly two checks as the answer: more rows than the reported page can hold, and a total smaller than the jobs beside it. Three rewrites slip past both. Case A returns page 3's records labelled `page: 1`, so a caller that walks pages 1..3 collects the same three jobs three times. Case E is the sharpest: three pages yield 9 rows containing 7 distinct jobs, two duplicated and two never returned, while every page insists `total: 9`, `total_pages: 3` and `filters.order: "DESC"` — the caller believes it has the complete set. Case F reports `per_page: 10` beside `total: 9` and `total_pages: 3`, which cannot all be true at once. Each needs a site callback on `post_type=job` queries, which is an ordinary theme snippet; the plugin's own archive.php registers one.

**Reproduction:**

```
All three run entirely in-process (add_action / remove_action around the call), so nothing was left on the site. Corpus at the time: 9 listed jobs.

  == A) a site pre_get_posts that rewrites 'paged' ==   ($q->set('paged', 3))
    response says page=1 per_page=3 total=9 total_pages=3
    jobs actually returned: 188,186,185
    (real page 3 is: 188,186,185)

  == E) pre_get_posts: orderby => rand ==
    page 1 (response says order=DESC, total=9, total_pages=3): 185,187,189
    page 2 (response says order=DESC, total=9, total_pages=3): 186,184,397
    page 3 (response says order=DESC, total=9, total_pages=3): 189,188,185
    union size=9 distinct=7 repeated=185,189
    omitted entirely: 400,392

  == F) pre_get_posts: posts_per_page reduced ==   ($q->set('posts_per_page', 3))
    per_page=10 total=9 total_pages=3 jobs=3  ids=187,184,400

Controls, same harness, both correctly refused:

  == G) pre_get_posts: posts_per_page increased ==
    refused: jpkcom_acf_jobs_filter_not_applied (correct)
  == H) pre_get_posts: no_found_rows ==
    refused: jpkcom_acf_jobs_filter_not_applied (correct)
```

**Refuter's verdict:** Reproduced all three cases on /home/jpk/ddev/posts against the committed tree, in-process via add_action/remove_action around jpkcom_acf_jobs_ability_query_jobs(). A) pre_get_posts $q->set('paged',3) with input per_page=3,page=1 returned ids 481,480,474 — byte-identical to the baseline's page 3 — while the response said page=1, total=13, total_pages=5. E) $q->set('orderby','rand') over pages 1..3 returned 9 rows, 6 distinct, 188/486/482 repeated, 484/483/480/474 never returned, every page reporting total=13, total_pages=5, filters.order='DESC'. F) $q->set('posts_per_page',3) with per_page=10 returned per_page=10 total=13 total_pages=5 jobs=3, i.e. total_pages != ceil(total/per_page). Controls G (posts_per_page increased) and H (no_found_rows) both refused with jpkcom_acf_jobs_filter_not_applied status 500, confirming the harness reaches the guards.

No later layer stops it. abilities.php:2963-2995 holds the only two post-hoc checks (count($posts) > $per_page; $total < count($posts)); case F passes both because 3<=10 and 13>=3. grep for query_vars in abilities.php returns nothing — the executed query is never read back, by deliberate design (CLAUDE.md point 9). unbacked_claim and query_divergence both run on $args before new WP_Query, so pre_get_posts is structurally after them.

Not covered by existing assertions: php tests/test-abilities.php gives 453 passed, 0 failed, and jpkcom_test_pre_get_posts is driven at exactly one place (test-abilities.php:1675-1704) with exactly two cases, posts_per_archive_page and no_found_rows — the finding's own controls.

One sub-claim of the finding does NOT survive: "the plugin's own archive.php registers one" is misleading. A tracer at priority 9999 shows the ability's query is is_main_query => 'no', and includes/archive.php:41 guards with $query->is_main_query(), so the shipped callback provably cannot reach it. A third-party callback is required. Case A is contrived, E moderately so; only F (an unguarded page-size snippet) is re

---

## [minor] query-jobs accepts a company ID the site does not publish, echoes it in filters as applied, and returns a job whose own record shows no company at all

**Lens:** wrong-answers

**Consequence:** The response asserts a company filter was applied and matched one job, `unknown` is empty (so the value is reported as recognised), and the single returned job carries `companies: []`. A model has been told "1 open position at company 409" by a response whose own payload contains no evidence of any company, and list-filters will never offer 409 so the two abilities disagree about which values exist. Same for a private, future-dated or trashed job_company/job_location.

**Reproduction:**

```
  $ ddev wp eval-file numlens-probe10.php
  draft company=409  job=410
  list-filters offers companies: 286,182  contains 409? false
  query-jobs company=[409]:
    filters={"include_closed":true,"order":"DESC","company":[409]}
    unknown={}
    total=1
    job 410 'NUMLENS Job bei Entwurfsfirma' companies=[]
  get-job 410 companies=[] detail.companies=[]
  (cleaned up 410 409)

409 is a `job_company` post with post_status 'draft'. abilities.php:2648 accepts it because `get_post_type( $id ) === 'job_company'` is true for any status, while `jpkcom_acf_jobs_ability_related_vocabulary()` offers published records only and `jpkcom_acf_jobs_normalise_related()` drops unpublished ones from the record.
```

**Refuter's verdict:** Reproduced independently on /home/jpk/ddev/posts against the committed tree (branch tip 5931479; task named ancestor 181f56f). My first rebuild FAILED (total=0) because I wrote job_company as serialized ints while ACF writes strings and the clause is LIKE '"<id>"'; with ACF's real shape (per job 184: a:1:{i:0;s:3:"182";}) it reproduces exactly: draft company 496, published job 497, list-filters companies 182,475 (contains 496? false), query-jobs company=[496] -> filters={"include_closed":true,"order":"DESC","company":[496]}, unknown={}, total=1, job 497 companies=[]. One process per status with wp_cache_flush (an earlier single-process run gave a false negative from stale post cache, which I discarded): draft/pending/private/trash/password all offered=no, filters echoes the id, unknown={}, total=1, job.companies=[]. The finding's "future" case needed a genuinely future post_date (wp_update_post flips a non-future date back to publish); with one it reproduces (id=545 db_status=future offered=no total=1 companies=[]). Location axis identical (filters.location=[501] unknown={} total=1 locations=[]). Mechanism confirmed: abilities.php:2648 get_post_type()===$related_type is status-blind; abilities.php:531-533 vocabulary queries post_status=>publish + has_password=>false; jobs-data.php:464 drops non-publish/password-protected from the record. I searched for the guard that would moot it: company/location carry no 'enum' in the input schema (only job_type does, abilities.php:1754), there is no post-filter dropping the returned job, and the unbacked_claim/commitment layer only verifies the clause was built and executed - which it was. Not covered by the suite: php tests/test-abilities.php gives 453 passed, 0 failed; tests/lib.php:177 defines draft company 500 and password-protected 501 but they are asserted only against related_vocabulary's projection (test-abilities.php:210-216), and the company-axis test at 857-884 covers wrong type (183) and nonexistent (999), never wron

---

## [minor] list-filters over-counts a company when the stored job_company array names it twice, so its count exceeds the total query-jobs returns for the same filter

**Lens:** wrong-answers

**Consequence:** The count list-filters offers as "Number of currently listed jobs for this company" exceeds the number of jobs query-jobs will hand back for exactly that filter, by one per duplicate entry. A model that calls list-filters to plan and then query-jobs to fetch sees jobs go missing and has no way to tell whether the shortfall is a paging bug. Reachable only through a non-UI writer (importer, WP-CLI, WPML copy, direct SQL) — the ACF post_object UI will not select the same post twice — so I wrote the meta directly.

**Reproduction:**

```
  $ ddev wp eval-file numlens-probe8.php
  == duplicate company id in the stored meta ==
    stored=array (
    0 => '182',
    1 => '182',
  )
    list-filters count(182)=8  query-jobs total(company=182)=7  ids=187,184,403,189,188,186,185  *** MISMATCH

The tally at abilities.php:2278-2288 increments once per entry returned by `jpkcom_acf_jobs_normalise_related()`, which projects each array element separately; the query side is a single `LIKE '"182"'` clause that matches the row once.
```

**Refuter's verdict:** Reproduced independently and it holds, in the claimed direction, on a clean corpus. On /home/jpk/ddev/posts with exactly the six fixture jobs 184-189, after `update_field('job_company',[182,182],189)` (which returned true): `list-filters company 182 count=7` while `query-jobs total=6  ids=187,184,189,188,186,185` — the six ids are the entire corpus, so there is no seventh job to page to. An isolated variant with a throwaway company nothing else referenced gives `count(517)=2  total(company=517)=1  ids=189`, with `filters.company=[517]` confirming the clause really was applied, so this is a wrong tally beside a correct query, not the dropped-clause class.

Mechanism confirmed in code: abilities.php:2278 increments once per entry from jpkcom_acf_jobs_normalise_related() (jobs-data.php:428-479, no dedupe), while the query side builds one LIKE clause per DISTINCT id (abilities.php:855 array_unique) and a row matches a LIKE once. ACF post_object::load_value() (6.8.6, class-acf-field-post_object.php:470-479) only maps the ACF4 'null' sentinel and does not collapse duplicates.

Caution about my own first attempt: it appeared to refute the finding — get_field(...,false) returned one element after I wrote two — but that was a stale read from ACF's in-memory value store, which wp_cache_flush() does not touch. Adding acf_get_store('values')->reset() made the duplicate come through. Anyone re-checking this must reset that store or they will produce a false refutation.

I hunted for the guard at the next layer and found the opposite. The compact record and get-job build their companies block from the FORMATTED read (jobs-data.php:641), and ACF's format_value resolves through WP_Query post__in, which collapses the duplicate — the same response therefore shows `"companies": [{"id":182,...}]` (one company) while list-filters counted that job twice. The tally is the only reader on the unformatted path, deliberately so for query cost (abilities.php:2274-2277), and the only one that d

---

## [minor] A corrupt salary currency or period — the two select sub-fields — fatals get-job on the detail path

**Lens:** floor-and-throw

**Consequence:** `get-job` for that one job is an uncaught fatal on 6.9.4. Narrower than the job_type case — jobs-data.php:827 sits behind the detail-page gate, so it is reachable only for a job that renders (no job_url, not expired, no password) and never from `query-jobs`. Worth stating that the guard immediately below it, `is_numeric( $salary_group['job_salary'] )`, correctly refuses a non-numeric and an array amount; the hole is the two `select` sub-fields beside it, which are formatted before that guard is ever reached.

**Reproduction:**

```
  update_post_meta( $id, 'job_base_salary_group_job_salary_currency', (object) [ 'x' => 1 ] );
  update_post_meta( $id, '_job_base_salary_group_job_salary_currency', 'field_68de69ae89fdf' );
  update_post_meta( $id, 'job_base_salary_group', '' );

  $ ddev exec bash -c 'FT_ID=234 wp eval-file floor-trace.php'
  TypeError: Cannot access offset of type stdClass in isset or empty
  #0 .../class-acf-field-select.php(710): acf_maybe_get()
  #1 .../class-acf-field-select.php(688): acf_field_select->format_value_single()
  #2 .../class-acf-field-select.php: acf_field_select->format_value()
  #8 .../class-acf-field-group.php(141): acf_format_value()
  #16 /var/www/html/wp-content/plugins/jpkcom-acf-jobs/includes/jobs-data.php(827): get_field()
  #17 /var/www/html/wp-content/plugins/jpkcom-acf-jobs/includes/abilities.php(3424): jpkcom_acf_jobs_get_job_data()

Sweep:
  job_base_salary_group_job_salary_currency  object [313] THROWABLE   arr_of_obj [314] THROWABLE
  job_base_salary_group_job_salary_period    object [317] THROWABLE   arr_of_obj [318] THROWABLE
  job_base_salary_group (the group itself), job_base_salary_group_job_salary (the number): all four payloads ok

  $ curl -sk -g -u "ffsub:$PW" -o /dev/null -w "HTTP %{http_code}\n" ".../get-job/run?input[id]=313"
  HTTP 500      (317 likewise)
```

**Refuter's verdict:** CONFIRMED in mechanism and consequence, with one correction to the repro and a downgrade in severity.

(1) The literal quoted repro does NOT reproduce on a bare fixture. Three metas alone yield "OK ... detail.salary: 'ABSENT'". Cause: acf_maybe_get_field(selector, post_id, strict=true) (api-template.php:300) needs the _job_base_salary_group reference row; without it get_field builds a dummy field and forces $format_value=false (api-template.php:36-48), so ACF never formats. Adding update_post_meta($id,'_job_base_salary_group','field_68de5e180b619') reproduces the stack frame-for-frame: "THROW TypeError: Cannot access offset of type stdClass in isset or empty / #0 class-acf-field-select.php(710): acf_maybe_get / #1 (688): format_value_single / #8 class-acf-field-group.php(141): acf_format_value / #15 api-template.php(75) / #16 jobs-data.php(827): get_field". That row exists on every job whose salary was ever saved — verified on the posts instance: "wp post meta list 458" shows "_job_base_salary_group,field_68de5e180b619". So the omission is a fixture artefact, not a flaw.

(2) Sweep reproduces exactly: currency object/arr_of_obj THROWABLE, period object/arr_of_obj THROWABLE, arr_of_str and string fine, job_salary all five payloads ok, group meta itself all five ok. The is_numeric amount guard does hold, as the finding says.

(3) HTTP confirmed: get-job?input[id]=424 -> {"code":"ability_callback_exception","message":"...Cannot access offset of type stdClass in isset or empty"} HTTP 500; get-job id=171 -> 200; query-jobs per_page=20 -> 200 listing the corrupt job; list-filters -> 200. Detail gate verified upstream: job_url -> 200 reason=redirects_externally, expired -> 200 reason=expired, clean -> 500.

(4) Floor premise verified by source, not measured: both named instances are WP 7.0.2, whose WP_Ability::invoke_callback (class-wp-ability.php:513-525) DOES try/catch into a WP_Error — which is why the 500 has a body. I read a real 6.9.4 tree instead (/home/jpk/ddev/blo

---

## [minor] A stored ACF value makes the callback throw; on the declared 6.9 floor that is an uncaught fatal, and it takes out query-jobs with no input at all

**Lens:** consumers

**Consequence:** On the declared WordPress 6.9 floor a single stored meta value — not caller input — turns `query-jobs` called with no arguments at all into an uncaught fatal: a blank 500 with no body, no code, nothing an agent can act on. On 7.0.x the same value yields HTTP 500 with `data: null` (so no status was ever set by the plugin) and a body whose entire content is a raw PHP engine string, handed to any logged-in subscriber over REST and to any MCP client as an isError text block. The failure is not isolable by the caller: it follows whichever page of the corpus contains the poisoned job, so a consumer paginating the archive hits a hard wall on one page and cannot skip past it, and the wall moves as the corpus changes. This is exactly the class the file's own docblock says cannot happen ("Every callback in this file returns a WP_Error rather than throwing") — the guards cover the plugin's own arithmetic but not the get_field() call that does the reading.

**Reproduction:**

```
One job whose job_type checkbox meta holds a nested array — the shape a bad importer or a WPML base64 round-trip produces:

  $ ddev wp eval-file t-lens.php   # wp_insert_post job + update_post_meta($id,'job_type',[['x']]) + _job_type field key
  NEWID=408

  $ curl -g -sk -u "$S" -o z.json -w 'HTTP %{http_code}\n' \
      "https://posts.ddev.site/wp-json/wp-abilities/v1/abilities/jpkcom-acf-jobs/get-job/run?input[id]=408"
  HTTP 500
  {"code":"ability_callback_exception","message":"Der Callback der Fähigkeit „jpkcom-acf-jobs/get-job“ hat eine Ausnahme ausgelöst: Cannot access offset of type array in isset or empty","data":null}

  $ curl -g -sk -u "$S" -o z.json -w 'HTTP %{http_code}\n' \
      "https://posts.ddev.site/wp-json/wp-abilities/v1/abilities/jpkcom-acf-jobs/query-jobs/run"
  HTTP 500
  {"code":"ability_callback_exception","message":"Der Callback der Fähigkeit „jpkcom-acf-jobs/query-jobs“ hat eine Ausnahme ausgelöst: Cannot access offset of type array in isset or empty","data":null}

Same through the MCP consumer (HTTP 200, JSON-RPC result):
  {"result":{"content":[{"type":"text","text":"Der Callback der Fähigkeit „jpkcom-acf-jobs/query-jobs“ hat eine Ausnahme ausgelöst: Cannot access offset of type array in isset or empty"}],"isError":true}}

Origin (in-process trace on the second instance, same fixture):
  TypeError: Cannot access offset of type array in isset or empty
    advanced-custom-fields-pro/includes/api/api-helpers.php:2566   (acf_maybe_get)
    .../fields/class-acf-field-select.php:710 -> :685 (format_value_single)
    .../fields/class-acf-field-checkbox.php:562 (format_value)
  i.e. it is thrown inside get_field(), below every guard the plugin owns.

Blast radius is per page, not per record:
  order=DESC page=1 -> HTTP 500
  order=ASC          -> HTTP 200 total=123
  page=3             -> HTTP 200
list-filters is unaffected (HTTP 200) because it never reads job_type per job.

What the floor actually does — measured against the real 6.9.4, since test2 was upgraded out from under the run:
  $ curl -sSL -o wp694.tar.gz https://wordpress.org/wordpress-6.9.4.tar.gz && tar xzf ...
  $ grep 'wp_version =' wordpress/wp-includes/version.php
  $wp_version = '6.9.4';
  $ grep -c catch wordpress/wp-includes/abilities-api/class-wp-ability.php
  0
  $ grep -n 'catch ( Throwable' /home/jpk/ddev/posts/wp-includes/abilities-api/class-wp-ability.php
  515:		} catch ( Throwable $e ) {
WP_Ability::execute() on 6.9.4 ends at `return $result;` with no tr
```

**Refuter's verdict:** Mechanism confirmed, quoted evidence partly wrong, severity inflated.

CONFIRMED: get_field('job_type',$id,true) at jobs-data.php:711 is unguarded, and a nested-array meta value throws inside ACF: checkbox format_value:562 -> select format_value:685 -> format_value_single:710 -> acf_maybe_get (api-helpers.php:2566, `isset($array[$key])` with an array key). Line numbers match ACF 6.8.6 exactly. $label is computed before the return_format branch, so no field config avoids it. Only try/catch in either ability file is jobs-data.php:342 (normalise_date), so no layer stops it. Reproduced on /home/jpk/ddev/posts as a subscriber: get-job/run?input[id]=547 -> HTTP 500 {"code":"ability_callback_exception",...,"data":null}; via mcp-adapter-execute-ability -> HTTP 200 isError with the raw engine string. 6.9 floor verified from a fresh 6.9.4 download: grep -c catch class-wp-ability.php = 0, no catch anywhere in wp-includes/abilities-api/ or the run controller, execute() ends at return $result; 7.0.2 adds the catch at line 515. Not covered by tests: tests/lib.php:417 stubs get_field as a pass-through, so the suite structurally cannot see a throw inside real ACF. list-filters unaffected (200).

REFUTED:
1. The quoted query-jobs reproduction does NOT reproduce. With the literal fixture (wp_insert_post + job_type + _job_type) query-jobs returned HTTP 200 total=6 with visibility.hidden_missing_featured=1 — jobs-data.php:108 puts a job_featured EXISTS clause in every query and names the exclusion. Only after `ddev wp post meta update 547 job_featured 0` did it 500. The finding's own total=123 is internally inconsistent with its claim.
2. ACF cannot create the shape. update_field('job_type',[['value'=>...,'label'=>...]],547) -> "Array to string conversion" at class-acf-field-select.php:569 (array_map 'strval') and stores array(1){[0]=>string(5)"Array"}. Requires raw update_post_meta/SQL/importer. The "WPML base64 round-trip" justification is asserted, never demonstrated.
3. Not ability

---

## [minor] query-jobs.visibility is global, not scoped to the answer it is attached to

**Lens:** consumers

**Consequence:** The numbers never move with the filters, so "excluded from this answer" is false for every filtered call. A model that reads them as scoped — which is what the sentence says — tells the user "0 results, but 1 more expired job matched and was hidden" for a search that matches nothing at all. It is the same misreading in the other direction as finding 4: there the emptiness is presented as complete when it is not, here the emptiness is presented as incomplete when it is. Either "of this site" instead of "from this answer", or scoping the counts to the executed filters, resolves it; the list-filters copy of the same block is worded correctly ("How many published jobs this site actually lists").

**Reproduction:**

```
The schema says: out.visibility — "Published jobs excluded from this answer by the site visibility rule rather than by the filters."

$ R="https://posts.ddev.site/wp-json/wp-abilities/v1/abilities/jpkcom-acf-jobs/query-jobs/run"
$ curl -g -sk -u "$S" "$R"
  total=7   visibility={"hidden_missing_featured":0,"hidden_expired":1}
$ curl -g -sk -u "$S" "$R?input[search]=zzzznomatch"
  total=0   visibility={"hidden_missing_featured":0,"hidden_expired":1}
$ curl -g -sk -u "$S" "$R?input[company][]=999999"
  total=0   visibility={"hidden_missing_featured":0,"hidden_expired":1}

Earlier in the run, on a larger corpus, the same invariance at every filter:
  (no filter)                    total=9  vis={"hidden_missing_featured":1,"hidden_expired":3}
  input[company][]=182           total=6  vis={"hidden_missing_featured":1,"hidden_expired":3}
  input[attribute][]=firmenwagen total=4  vis={"hidden_missing_featured":1,"hidden_expired":3}
  input[search]=zzzzznothing...  total=0  vis={"hidden_missing_featured":1,"hidden_expired":3}
```

**Refuter's verdict:** Reproduced independently and could not refute it. Code: includes/abilities.php:3066 calls jpkcom_acf_jobs_ability_visibility_counts(), which (:624) takes no argument and runs two fixed site-wide count queries over post_type=job/post_status=publish/has_password=false — no filter, meta_query or tax_query from the request reaches it. It is literally the same call list-filters makes at :2346. Live on /home/jpk/ddev/posts (rsynced, seeded): total moved 12 (no filter) -> 6 (company 182) -> 2 (company 548) -> 0 (search zzzznomatch) -> 4 (attribute firmenwagen) while visibility stayed {"hidden_missing_featured":1,"hidden_expired":2} on every one. (My corpus gave hidden_expired 2, not the quoted 1/3 — a seed difference, not a mechanism difference: job 552's '2025-11-30 00:00:00' is expired to the rule's CAST(... AS DATE).) No guard stops it at any layer: GET /wp-abilities/v1/abilities/jpkcom-acf-jobs/query-jobs returns the description verbatim to the plain subscriber ("Published jobs excluded from this answer by the site visibility rule rather than by the filters."), nothing else in the payload scopes the block, and README.md never mentions hidden_expired or hidden_missing_featured, so that sentence is the only contract. The claim is falsifiable per job, not merely ambiguous: the three hidden fixtures 550/551/552 all have company='' (verified by wp eval), so on the company=[548] answer the response asserts three jobs were withheld by the visibility rule "rather than by the filters" when the company filter would have excluded all three anyway. Not covered by any assertion: tests/test-abilities.php:572 checks only is_int() on both counts and never compares the block across two differently-filtered calls, so this would not have been caught before release. Severity is one notch high, though. It needs no precondition and a caller triggers it on every filtered call, which argues up, but both numbers are numerically correct, the two leaf descriptions directly beneath are globally f

---

## [minor] No output_schema declares required or additionalProperties, so "the response validates against its declared output_schema" is close to vacuous

**Lens:** consumers

**Consequence:** A consumer that generates client code or plans a call sequence from the declared output_schema is told that `id`, `title`, `jobs`, `total`, `filters` and `unknown` are all optional — there is no basis in the contract for expecting any of them, so a careful model writes null checks for every field and a careless one writes none, and neither is corrected by the schema. It also means the 41-response conformance pass I ran, and core's own per-execute output validation, would not have detected a dropped `jobs` array or a `filters` block that lost a key. Worse for the MCP consumer: `get-ability-info` hands the client this schema described as "JSON Schema for the ability output structure", but what `execute-ability` returns is `{success, data}` — a shape the schema also accepts, so the mismatch is undetectable by validation and only visible to a model that happens to notice the extra level.

**Reproduction:**

```
$ python3 -c "...check every declared output_schema..."
  jpkcom-acf-jobs/list-filters   output: required=None additionalProperties=None
      {} validates as output?                        True
      {"totally":"unrelated"} validates as output?   True
  jpkcom-acf-jobs/query-jobs     output: required=None additionalProperties=None
      {} validates as output?                        True
      {"totally":"unrelated"} validates as output?   True
  jpkcom-acf-jobs/get-job        output: required=None additionalProperties=None
      {} validates as output?                        True
      {"totally":"unrelated"} validates as output?   True

Demonstrated live: the MCP adapter wraps every ability result one level deeper —
  MCP structuredContent top-level keys: ['success', 'data']
  validates against ability output_schema? True      # the envelope passes
  sc["data"] validates?                    True      # the payload passes too
Both pass the same schema, so a client cannot tell which one it is holding.
Core runs this same check on every execute (class-wp-ability.php:587 rest_validate_value_from_schema), so nothing upstream catches a missing field either.
```

**Refuter's verdict:** I tried to refute this and could not refute the factual core, but three of the four consequence claims are overstated and one is wrong. Downgrade important -> minor.

== 1. THE REPRODUCTION REPRODUCES, AND STRONGER THAN CLAIMED ==

The finding used python jsonschema. That is the wrong validator: core uses rest_validate_value_from_schema (/home/jpk/ddev/posts/wp-includes/abilities-api/class-wp-ability.php:587, identical on test2 at :587). I re-ran the probe with WordPress's own validator after rsyncing the committed tree.

  $ rsync -a --delete --exclude='.git' --exclude='.claude' --exclude='.superpowers' /home/jpk/wp/jpkcom-acf-jobs/ /home/jpk/ddev/posts/wp-content/plugins/jpkcom-acf-jobs/
  $ ddev wp eval-file scratch-audit/schemacheck.php     # do_action('wp_abilities_api_init'); wp_get_ability(); rest_validate_value_from_schema($probe,$s,'output')

  jpkcom-acf-jobs/list-filters   type="object" required=null additionalProperties=null props=8
      {}                                     -> true
      {"totally":"unrelated"}                -> true
      {"jobs":"not-an-array"}                -> true
      {"success":true,"data":{"total":3}}    -> true
  jpkcom-acf-jobs/query-jobs     type="object" required=null additionalProperties=null props=10
      {}                                     -> true
      {"totally":"unrelated"}                -> true
      {"jobs":"not-an-array"}                -> WP_Error: output[jobs][0] ist nicht vom Typ object.
      {"success":true,"data":{"total":3}}    -> true
  jpkcom-acf-jobs/get-job        type="object" required=null additionalProperties=null props=20
      {}                                     -> true
      {"totally":"unrelated"}                -> true
      {"jobs":"not-an-array"}                -> true
      {"success":true,"data":{"total":3}}    -> true

Same over the wire, as an ordinary subscriber:
  $ curl -sg -u 'rfsub2:***' 'https://posts.ddev.site/wp-json/wp-abilities/v1/abilities/jpkcom-acf-jobs/query-jobs' | 

---

## [note] Encountered, not mine: a nested array in job_type meta makes query-jobs fatal out of the ability callback

**Lens:** wrong-answers

**Consequence:** One job row with a nested array in job_type takes down the entire query-jobs listing for every caller and every page — an uncaught Throwable out of an ability callback, which on the declared 6.9 floor is a blank 500 with no body. I am reporting it rather than claiming it: the row (post 349, title "FFLENS one bad meta row") was planted on test2 by another agent before I started and is that agent's fixture. I did not create it, did not reproduce it on /home/jpk/ddev/posts, and it is what stopped my test2 pagination sweep from completing.

**Reproduction:**

```
  $ cd /home/jpk/ddev/test2 && ddev wp eval-file numlens-t2.php
  wp_version=7.0.2  abilities_enabled=true
  registered: jpkcom-acf-jobs/list-filters, jpkcom-acf-jobs/query-jobs, jpkcom-acf-jobs/get-job
  visibility={"published_total":3,"listed_total":3,"hidden_missing_featured":0,"hidden_expired":2}
  PHP Fatal error:  Uncaught TypeError: Cannot access offset of type array in isset or empty in /var/www/html/wp-content/plugins/advanced-custom-fields-pro/includes/api/api-helpers.php:2566
  #0 .../class-acf-field-select.php(710): acf_maybe_get()
  #2 .../class-acf-field-checkbox.php(562): acf_field_select->format_value()
  #10 /var/www/html/wp-content/plugins/jpkcom-acf-jobs/includes/jobs-data.php(711): get_field()
  #11 /var/www/html/wp-content/plugins/jpkcom-acf-jobs/includes/abilities.php(3023): jpkcom_acf_jobs_get_job_data()
  #12 ...: jpkcom_acf_jobs_ability_query_jobs()

The triggering row:
  $ ddev wp eval '...SELECT post_id, meta_value FROM postmeta WHERE meta_key="job_type"...'
  349 => 'a:1:{i:0;a:1:{i:0;s:9:"FULL_TIME";}}'
  349 publish FFLENS one bad meta row

jobs-data.php:711 is `get_field( 'job_type', $post_id, true )` — the formatted read the compact record needs for {value,label} pairs. ACF's checkbox format_value passes each element to acf_maybe_get(), which throws when the element is an array.
```

**Refuter's verdict:** HOLDS at note — mechanism confirmed, consequence overstated, severity as claimed is right.

WHAT DID NOT REPRODUCE. The quoted reproduction is dead. `cd /home/jpk/ddev/test2 && ddev wp post get 349 --field=post_title` -> "Error: Could not find the post with ID 349." and `ddev wp db query 'SELECT post_id, meta_value FROM jpkA11yTpl1_postmeta WHERE meta_key="job_type"'` -> 171/172/195/196 all `a:1:{i:0;s:9:"FULL_TIME";}`, no nested row. Also `ddev wp core version` -> `7.0.2` on test2, so the instance the brief calls "THE DECLARED FLOOR (6.9.4)" is not on the floor. I did not change it.

WHAT DID REPRODUCE. Planting the row myself against the rsynced committed tree: `ddev wp eval 'update_post_meta(195,"job_type",[["FULL_TIME"]]);'` -> stored `a:1:{i:0;a:1:{i:0;s:9:"FULL_TIME";}}`; then calling `jpkcom_acf_jobs_ability_query_jobs([])` -> `Uncaught TypeError: Cannot access offset of type array in isset or empty ... api-helpers.php:2566`, frames `#1 class-acf-field-select.php(685) format_value_single #2 class-acf-field-checkbox.php(562) #10 jobs-data.php(711) get_field #11 abilities.php(3023)`. ACF 6.8.6 `acf_maybe_get()` is literally `isset( $array[ $key ] )` and `format_value_single()` hands the element in unchecked. So the mechanism is real, not fabricated.

THE GUARD THE FINDING WALKED PAST. Its own trace shows frame #12 = eval'd code calling `jpkcom_acf_jobs_ability_query_jobs()` directly — the plain function, never the ability. Through the ability, `WP_Ability::do_execute()` -> `invoke_callback()` at `wp-includes/abilities-api/class-wp-ability.php:513` is `try { return $callback( ...$args ); } catch ( Throwable $e ) { return new WP_Error( 'ability_callback_exception', ... ) }`. Measured with the bad row still planted: `wp_get_ability('jpkcom-acf-jobs/query-jobs')->execute([])` -> `WP_Error code=ability_callback_exception msg=... Cannot access offset of type array in isset or empty` followed by `STILL ALIVE`. Over HTTP as a subscriber: `curl -sk -g -u 'rfsal:...' 'ht

---

## [note] absint() of a serialized object inside the read-only path raises a PHP warning and silently resolves the object to post ID 1

**Lens:** floor-and-throw

**Consequence:** A read-only ability emits an E_WARNING for a data condition it is meant to tolerate. Two costs. On a site running WP_DEBUG_DISPLAY (the plugin's own documented development posture — CLAUDE.md tells developers to switch WP_DEBUG on), the warning is printed into the REST body and the JSON stops parsing. And the guard immediately below it, `if ( $id < 1 ) continue;` — written specifically so that an unresolvable value never reaches get_post() — does not hold for an object, because absint() maps every object to 1 rather than to 0; only ACF's separate post_type filter keeps post ID 1 out of the company list on the formatted path. An `is_scalar()` test before absint() would close both.

**Reproduction:**

```
Fixture: job_company holding [ (object) [ 'ID' => 182 ] ] (test2 job 225), then list-filters, which reads that field UNFORMATTED:

  DIAG: list-filters :: [2] Object of class stdClass could not be converted to int @ load.php:1469

load.php:1469 is `absint()`, reached from includes/jobs-data.php:452 in `jpkcom_acf_jobs_normalise_related()`:

  $id = absint( $item );
  if ( $id < 1 ) { continue; }
  $post = get_post( $id );

absint() of an object yields 1, so the lookup becomes get_post(1). On test2 post 1 is a published post:
  $ ddev wp eval '…' -> post 1 = ["post","publish","Hallo Welt!"]
The record was nevertheless empty (`225 companies=[] locations=[]`) because in `get_job_data()` the same field is read FORMATTED and ACF's own post_type constraint filters post 1 out first. list-filters, which reads it unformatted, is protected only by `isset( $company_index[ $id ] )`.

The HTTP response stayed well-formed on these instances (WP_DEBUG_DISPLAY is off):
  $ curl -sk -g -u "ffsub:$PW" -o /dev/null -w "HTTP %{http_code}\n" ".../list-filters/run"
  HTTP 200
```

**Refuter's verdict:** Reproduced independently and end-to-end on test2 against the committed tree. A job whose raw job_company meta holds a serialized stdClass makes list-filters emit `[2] Object of class stdClass could not be converted to int @ load.php:1469` (load.php:1469 is verbatim `return abs( (int) $maybeint );` inside absint()), reached from jobs-data.php:452 via the UNFORMATTED read at abilities.php:2278; normalise_related() returns `[['id'=>1,'title'=>'Hallo Welt!']]`, so absint() does map the object to post ID 1 past the `if ( $id < 1 ) continue;` guard. The warning reaches the FPM/nginx error log on every request even in the default posture (WP_DEBUG off, Accept: application/json, HTTP 200, clean JSON). The formatted path in get_job_data() is unaffected, exactly as the finding says. Two corrections. (a) The finding's own fixture is incomplete: a job with no job_featured meta row is not in the visible set (`visible ids = 424,422,195,171`, fixture not in set), so the warning only fires once job_featured exists. (b) The claimed second cost is conditional, not unconditional: wp_debug_mode() in core ends with a block that forces display_errors=0 when wp_is_json_request(), so with WP_DEBUG_DISPLAY emulated per-request I measured `Accept: */*` -> body prefixed with the Warning HTML and JSONDecodeError, but `Accept: application/json` -> 602 bytes, PARSES. Every MCP/wp-json client that announces JSON is immune; only a bare-curl-style client sees the broken body. Severity is note, not minor: no caller input can reach this. ACF's own post_object update_value() maps every element through acf_idval() (object -> ->ID) before storage, so no supported ACF write path produces the fixture — it takes a direct DB/import write or the site's own acf/load_value callback (I measured the filter path; it reproduces). Nothing throws, so the "must not throw" floor rule is not violated, and nothing leaks: post 1 can only surface in output if post ID 1 is itself a published job_company in the vocabulary, 

---

