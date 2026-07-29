<?php
/**
 * ============================================================
 * File     : includes/profiling_view.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Shared HTML renderer for the KK Profiling form (P11 v2),
 *            used by both pages/youth/profile.php (self-service) and
 *            pages/manage/kk_profile.php (SK-assisted entry) so the ~400
 *            lines of sectioned form markup exist in exactly one place.
 *
 * Callers render their own page shell (head/nav/page-header) and just
 * invoke sked_render_kk_profile_form() for the form body.
 * ============================================================
 */

require_once __DIR__ . '/profiling.php';
require_once __DIR__ . '/view.php';

/**
 * @param array<string,mixed> $v         Resolved current form values (POST-on-error, or DB values, already flattened).
 * @param array<string,mixed> $identity  sked_user_identity() result for the target youth.
 * @param bool                $disabled  True to render every input disabled (demo preview).
 * @param string               $csrfToken
 * @param string               $formAction
 */
function sked_render_kk_profile_form(array $v, array $identity, bool $disabled, string $csrfToken, string $formAction): void
{
    $options = sked_profiling_options();
    $consent = sked_kk_consent_text();
    $dis = $disabled ? ' disabled' : '';

    $age = !empty($identity['birthdate']) ? sked_age_from_birthdate((string) $identity['birthdate']) : null;
    $ageGroup = sked_youth_age_group($age);
    $sexLabels = ['male' => 'Male (Lalaki)', 'female' => 'Female (Babae)'];
    $barangayName = !empty($identity['barangay_id']) ? sked_barangay_name((int) $identity['barangay_id']) : '';
    $consentBarangay = $barangayName !== '' ? 'Barangay ' . $barangayName : 'Barangay';
    $alignConsentLocation = static function (string $text) use ($consentBarangay): string {
        return str_replace(
            [
                'Barangay J. P Rizal Siniloan, Laguna',
                'Barangay J. P. Rizal Siniloan, Laguna',
                'Barangay J.P. Rizal Siniloan, Laguna',
                'Barangay J. P Rizal',
                'Barangay J. P. Rizal',
                'Barangay J.P. Rizal',
                'Brgy. J. P Rizal Siniloan, Laguna',
                'Brgy. J. P. Rizal Siniloan, Laguna',
                'Brgy. J.P. Rizal Siniloan, Laguna',
                'Brgy. J. P Rizal',
                'Brgy. J. P. Rizal',
                'Brgy. J.P. Rizal',
            ],
            [
                $consentBarangay . ' Siniloan, Laguna',
                $consentBarangay . ' Siniloan, Laguna',
                $consentBarangay . ' Siniloan, Laguna',
                $consentBarangay,
                $consentBarangay,
                $consentBarangay,
                $consentBarangay . ' Siniloan, Laguna',
                $consentBarangay . ' Siniloan, Laguna',
                $consentBarangay . ' Siniloan, Laguna',
                $consentBarangay,
                $consentBarangay,
                $consentBarangay,
            ],
            $text
        );
    };

    $classifications = (array) ($v['classifications'] ?? []);
    $specificNeeds = (array) ($v['specific_needs'] ?? []);
    $scholarships = (array) ($v['scholarships'] ?? []);
    $interests = (array) ($v['interests'] ?? []);
    $preferredPrograms = (array) ($v['preferred_programs'] ?? []);
    $attended = (string) ($v['attended_kk_assembly'] ?? '');

    /** Yes/No radio pair. */
    $yesNo = static function (string $field, string $current, string $idPrefix = '') use ($dis) {
        $idPrefix = $idPrefix !== '' ? $idPrefix : $field;
        echo '<div class="d-flex gap-3">';
        foreach (['1' => 'Yes (Oo)', '0' => 'No (Hindi)'] as $val => $label) {
            $checked = $current === $val ? ' checked' : '';
            echo '<div class="form-check">'
                . '<input class="form-check-input" type="radio" name="' . e($field) . '" id="' . e($idPrefix . '_' . $val) . '" value="' . e($val) . '"' . $checked . $dis . '>'
                . '<label class="form-check-label" for="' . e($idPrefix . '_' . $val) . '">' . e($label) . '</label>'
                . '</div>';
        }
        echo '</div>';
    };

    /** Single-choice <select>. $useKeyAsValue: true for int-keyed maps (num_children, kk_assembly_times); false for plain label lists. */
    $select = static function (string $field, array $choices, string $current, bool $useKeyAsValue = false, string $placeholder = 'Select…') use ($dis) {
        echo '<select class="form-select" id="' . e($field) . '" name="' . e($field) . '"' . $dis . '>';
        echo '<option value="">' . e($placeholder) . '</option>';
        foreach ($choices as $key => $label) {
            $val = $useKeyAsValue ? (string) $key : $label;
            $sel = ($val === $current) ? ' selected' : '';
            echo '<option value="' . e($val) . '"' . $sel . '>' . e($label) . '</option>';
        }
        echo '</select>';
    };

    /** Checkbox group. $current is the list of already-selected values. */
    $checkGroup = static function (string $name, array $choices, array $current, string $idPrefix, string $extraClass = '') use ($dis) {
        foreach ($choices as $i => $choice) {
            $checked = in_array($choice, $current, true) ? ' checked' : '';
            $id = e($idPrefix . $i);
            echo '<div class="form-check' . ($extraClass !== '' ? ' ' . e($extraClass) : '') . '">'
                . '<input class="form-check-input" type="checkbox" name="' . e($name) . '[]" id="' . $id . '" value="' . e($choice) . '"' . $checked . $dis . '>'
                . '<label class="form-check-label" for="' . $id . '">' . e($choice) . '</label>'
                . '</div>';
        }
    };
    ?>
    <form method="post" action="<?php echo e($formAction); ?>" novalidate id="kkProfileForm">
        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

        <!-- Consent / research information -->
        <div class="docket-panel mb-4">
            <div class="section-heading"><h2>Kabatiran sa Pagpapahintulot</h2><span class="section-note">Informed Consent</span></div>
            <p class="text-secondary" style="white-space:pre-line;"><?php echo e($alignConsentLocation($consent['intro'])); ?></p>
            <details class="mb-3">
                <summary class="fw-semibold" style="cursor:pointer;">Basahin ang buong impormasyon ng pag-aaral (Layunin, Panganib/Kumpidensyalidad, atbp.)</summary>
                <div class="mt-3">
                    <?php foreach ($consent['sections'] as $sec): ?>
                        <div class="mb-3">
                            <div class="fw-semibold"><?php echo e($sec['title']); ?></div>
                            <p class="text-secondary mb-0" style="white-space:pre-line;"><?php echo e($alignConsentLocation($sec['body'])); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>
            <div class="form-check border-top pt-3">
                <input class="form-check-input" type="checkbox" name="consent_agreed" id="consent_agreed" value="1" <?php echo !empty($v['consent_agreed']) ? 'checked' : ''; ?><?php echo $dis; ?> required>
                <label class="form-check-label" for="consent_agreed"><strong>Pahayag sa Pagsang-ayon:</strong> <?php echo e($consent['agreement']); ?></label>
            </div>
        </div>

        <!-- I. Profile (identity — autofilled + locked from registration) -->
        <div class="docket-panel mb-4">
            <div class="section-heading"><h2>I. Profile</h2><span class="section-note">Autofilled from registration &middot; locked</span></div>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Surname (Apelyido)</label>
                    <input type="text" class="form-control" value="<?php echo e((string) ($identity['surname'] ?? '') ?: '—'); ?>" disabled>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Given Name (Pangalan)</label>
                    <input type="text" class="form-control" value="<?php echo e((string) ($identity['given_name'] ?? '') ?: '—'); ?>" disabled>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Middle Name (Gitnang Pangalan)</label>
                    <input type="text" class="form-control" value="<?php echo e((string) ($identity['middle_name'] ?? '') ?: '—'); ?>" disabled>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Age (Edad)</label>
                    <input type="text" class="form-control" value="<?php echo e($age !== null ? (string) $age : '—'); ?>" disabled>
                    <small class="text-secondary">Computed from birthday</small>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Birthday (Araw ng Kapanganakan)</label>
                    <input type="text" class="form-control" value="<?php echo e(!empty($identity['birthdate']) ? date('F j, Y', strtotime((string) $identity['birthdate'])) : '—'); ?>" disabled>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Sex Assigned by Birth</label>
                    <input type="text" class="form-control" value="<?php echo e($sexLabels[$identity['sex_assigned_at_birth'] ?? ''] ?? '—'); ?>" disabled>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Youth Age Group</label>
                    <input type="text" class="form-control" value="<?php echo e($ageGroup !== '' ? $ageGroup : '—'); ?>" disabled>
                    <small class="text-secondary">Computed from age</small>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Email Address</label>
                    <input type="text" class="form-control" value="<?php echo e((string) ($identity['email'] ?? '') ?: '—'); ?>" disabled>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Contact Number</label>
                    <input type="text" class="form-control" value="<?php echo e((string) ($identity['mobile'] ?? '') ?: '—'); ?>" disabled>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Region</label>
                    <input type="text" class="form-control" value="<?php echo e(SKED_REGION_NAME); ?>" disabled>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Province</label>
                    <input type="text" class="form-control" value="<?php echo e(SKED_PROVINCE_NAME); ?>" disabled>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Municipality</label>
                    <input type="text" class="form-control" value="<?php echo e(SKED_DEFAULT_MUNICIPALITY); ?>" disabled>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Barangay</label>
                    <input type="text" class="form-control" value="<?php echo e($barangayName !== '' ? 'Barangay ' . $barangayName : '—'); ?>" disabled>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Purok</label>
                    <input type="text" class="form-control" value="<?php echo e((string) ($identity['purok'] ?? '') ?: '—'); ?>" disabled>
                </div>
            </div>
            <p class="text-secondary small mt-2 mb-0"><i class="bi bi-lock-fill me-1"></i>Name, birthday, sex, email, contact number, and address come from the registered account. To correct them, contact the Barangay SK.</p>
        </div>

        <!-- II. Demographic Characteristics -->
        <div class="docket-panel mb-4">
            <div class="section-heading"><h2>II. Demographic Characteristics</h2><span class="section-note">Demograpikong Katangian</span></div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label d-block">Gender Identity (Pagkakakilanlang Kasarian) <span class="text-danger">*</span></label>
                    <div class="d-flex gap-3">
                        <?php foreach (sked_gender_identity_options() as $val => $label): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="gender_identity" id="gender_<?php echo e($val); ?>" value="<?php echo e($val); ?>" <?php echo (($v['gender_identity'] ?? '') === $val) ? 'checked' : ''; ?><?php echo $dis; ?> required>
                                <label class="form-check-label" for="gender_<?php echo e($val); ?>"><?php echo e($label); ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="lgbtqia_member" id="lgbtqia_member" value="1" <?php echo !empty($v['lgbtqia_member']) ? 'checked' : ''; ?><?php echo $dis; ?>>
                        <label class="form-check-label" for="lgbtqia_member">Miyembro ng LGBTQIA</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <label for="civil_status" class="form-label">Civil Status (Katayuang Sibil) <span class="text-danger">*</span></label>
                    <?php $select('civil_status', $options['civil_status'], (string) ($v['civil_status'] ?? '')); ?>
                </div>
                <div class="col-md-6">
                    <label for="num_children" class="form-label">Kung ikaw ay isang kabataan na may anak na, ilan na ang iyong anak? <span class="text-danger">*</span></label>
                    <?php $select('num_children', sked_num_children_options(), (string) ($v['num_children'] ?? ''), true); ?>
                    <small class="text-secondary">Piliin ang N/A kung wala kang anak.</small>
                </div>
                <div class="col-md-6">
                    <label for="educational_attainment" class="form-label">Highest Educational Attainment <span class="text-danger">*</span></label>
                    <?php $select('educational_attainment', $options['educational_attainment'], (string) ($v['educational_attainment'] ?? '')); ?>
                </div>

                <div class="col-12"><hr></div>
                <div class="col-md-6">
                    <label class="form-label d-block">Scholarship</label>
                    <?php $checkGroup('scholarships', sked_scholarship_options(), $scholarships, 'scholarship', 'scholarship-opt'); ?>
                    <div class="mt-2" id="scholarshipOtherWrap" style="<?php echo in_array('Others', $scholarships, true) ? '' : 'display:none;'; ?>">
                        <input type="text" class="form-control form-control-sm" name="scholarship_other" maxlength="100" placeholder="Please specify" value="<?php echo e((string) ($v['scholarship_other'] ?? '')); ?>"<?php echo $dis; ?>>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label d-block">Youth Classification (Klasipikasyon ng Kabataan) <span class="text-danger">*</span></label>
                    <?php $checkGroup('classifications', sked_youth_classifications(), $classifications, 'classification', 'classification-opt'); ?>

                    <div id="specificNeedsWrap" class="mt-2 ps-3 border-start" style="<?php echo in_array(SKED_CLASSIFICATION_SPECIFIC_NEEDS, $classifications, true) ? '' : 'display:none;'; ?>">
                        <p class="text-secondary small mb-1">Ang tanong na ito ay partikular para sa mga kabataang may espesyal na pangangailangan.</p>
                        <?php $checkGroup('specific_needs', sked_specific_needs_options(), $specificNeeds, 'need'); ?>
                    </div>
                </div>

                <div class="col-12"><hr></div>
                <div class="col-md-6">
                    <label for="work_status" class="form-label">Work Status (Katayuan sa Trabaho) <span class="text-danger">*</span></label>
                    <?php $select('work_status', $options['work_status'], (string) ($v['work_status'] ?? '')); ?>
                </div>
                <div class="col-md-6">
                    <label for="valid_id" class="form-label">With Valid ID (May Valid ID) <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="valid_id" name="valid_id" maxlength="100" placeholder="Halimbawa: National ID, Driver's License. Kung wala, isulat ang WALA." value="<?php echo e((string) ($v['valid_id'] ?? '')); ?>"<?php echo $dis; ?> required>
                </div>

                <div class="col-md-4">
                    <label class="form-label d-block">Registered SK Voter? <span class="text-danger">*</span></label>
                    <?php $yesNo('sk_voter', (string) ($v['sk_voter'] ?? '')); ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label d-block">Registered National Voter?</label>
                    <?php $yesNo('national_voter', (string) ($v['national_voter'] ?? '')); ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label d-block">Did you vote last Election? <span class="text-secondary fw-normal">(Oct 30, 2023)</span></label>
                    <?php $yesNo('voted_last_election', (string) ($v['voted_last_election'] ?? '')); ?>
                </div>
            </div>
        </div>

        <!-- III. KK Assembly -->
        <div class="docket-panel mb-4">
            <div class="section-heading"><h2>III. KK Assembly</h2><span class="section-note">KK Asembliya</span></div>
            <p class="text-secondary small">Ang KK Assembly ay alinsunod sa Rule II Section 6 ng R.A. 10742 (Sangguniang Kabataan Reform Act of 2015) na naglalayong ipaalam sa mga nasasakupan ang mga programa, proyekto, at aktibidad para sa mga kabataan.</p>
            <div class="row g-3">
                <div class="col-md-12">
                    <label class="form-label d-block">Ikaw ba ay nakadalo na sa KK Assembly noong nakaraang taon? <span class="text-danger">*</span></label>
                    <?php $yesNo('attended_kk_assembly', $attended); ?>
                </div>
                <div class="col-md-6" id="kkTimesWrap" style="<?php echo $attended === '1' ? '' : 'display:none;'; ?>">
                    <label for="kk_assembly_times" class="form-label">Kung Oo, ilang beses? <span class="text-danger">*</span></label>
                    <?php $select('kk_assembly_times', sked_kk_assembly_times_options(), (string) ($v['kk_assembly_times'] ?? ''), true); ?>
                </div>
                <div class="col-md-6" id="kkAbsenceWrap" style="<?php echo $attended === '0' ? '' : 'display:none;'; ?>">
                    <label for="kk_assembly_absence_reason" class="form-label">Kung Hindi, bakit? <span class="text-danger">*</span></label>
                    <?php $select('kk_assembly_absence_reason', $options['kk_assembly_absence_reason'], (string) ($v['kk_assembly_absence_reason'] ?? '')); ?>
                </div>
            </div>
        </div>

        <!-- IV. KK Suggestions -->
        <div class="docket-panel mb-4">
            <div class="section-heading"><h2>IV. KK Suggestions</h2></div>
            <div class="mb-3">
                <label for="kk_suggestions" class="form-label">Mayroon ka bang mga mungkahi, alalahanin, o komento na sa tingin mo ay makakatulong sa Sangguniang Kabataan ng ating barangay sa pagbuo ng 2-taong rolling plan?</label>
                <textarea class="form-control" id="kk_suggestions" name="kk_suggestions" rows="3" maxlength="1000"<?php echo $dis; ?>><?php echo e((string) ($v['kk_suggestions'] ?? '')); ?></textarea>
            </div>

            <div class="mb-3">
                <label class="form-label d-block">What center of youth participation do you like the most? <span class="text-secondary fw-normal">Choose 1 to 3.</span> <span class="text-danger">*</span></label>
                <div class="row row-cols-2 row-cols-md-3">
                    <?php foreach (sked_interest_categories() as $i => $cat): ?>
                        <div class="col">
                            <div class="form-check">
                                <input class="form-check-input interest-opt" type="checkbox" name="interests[]" id="interest<?php echo (int) $i; ?>" value="<?php echo e($cat); ?>" <?php echo in_array($cat, $interests, true) ? 'checked' : ''; ?><?php echo $dis; ?>>
                                <label class="form-check-label" for="interest<?php echo (int) $i; ?>"><?php echo e($cat); ?></label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div>
                <label class="form-label d-block">What program, projects, and activities do you like the most? <span class="text-secondary fw-normal">Choose 3 to 5.</span> <span class="text-danger">*</span></label>
                <div class="row row-cols-1 row-cols-md-2">
                    <?php foreach (sked_preferred_programs() as $i => $prog): ?>
                        <div class="col">
                            <div class="form-check">
                                <input class="form-check-input program-opt" type="checkbox" name="preferred_programs[]" id="program<?php echo (int) $i; ?>" value="<?php echo e($prog); ?>" <?php echo in_array($prog, $preferredPrograms, true) ? 'checked' : ''; ?><?php echo $dis; ?>>
                                <label class="form-check-label" for="program<?php echo (int) $i; ?>"><?php echo e($prog); ?></label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-2">
                    <label class="form-label small">Other:</label>
                    <input type="text" class="form-control form-control-sm" name="preferred_programs_other" maxlength="150" value="<?php echo e((string) ($v['preferred_programs_other'] ?? '')); ?>"<?php echo $dis; ?>>
                </div>
            </div>
        </div>
        <?php
    // Conditional-question JS: show/hide follow-ups instead of always
    // displaying every branch (specific needs, KK Assembly follow-up,
    // scholarship "Others" text), and gently cap the 1-3 / 3-5 checkbox
    // groups so youth don't have to count their own selections.
    ?>
        <script>
        (function () {
            var form = document.getElementById('kkProfileForm');
            if (!form) { return; }

            function toggle(el, show) { el.style.display = show ? '' : 'none'; }

            // Specific-needs sub-question, conditional on the
            // "Youth with Specific Needs" classification checkbox.
            var needsWrap = document.getElementById('specificNeedsWrap');
            var needsTrigger = form.querySelector('.classification-opt[value="<?php echo e(SKED_CLASSIFICATION_SPECIFIC_NEEDS); ?>"]');
            if (needsTrigger && needsWrap) {
                needsTrigger.addEventListener('change', function () { toggle(needsWrap, this.checked); });
            }

            // Scholarship "Others" free-text, conditional on the Others checkbox.
            var otherWrap = document.getElementById('scholarshipOtherWrap');
            var otherTrigger = form.querySelector('.scholarship-opt[value="Others"]');
            if (otherTrigger && otherWrap) {
                otherTrigger.addEventListener('change', function () { toggle(otherWrap, this.checked); });
            }

            // KK Assembly Yes/No -> exactly one follow-up question.
            var timesWrap = document.getElementById('kkTimesWrap');
            var absenceWrap = document.getElementById('kkAbsenceWrap');
            form.querySelectorAll('input[name="attended_kk_assembly"]').forEach(function (radio) {
                radio.addEventListener('change', function () {
                    toggle(timesWrap, this.value === '1');
                    toggle(absenceWrap, this.value === '0');
                });
            });

            // Cap "center of youth participation" at 3 and "preferred programs" at 5.
            function capGroup(selector, max) {
                var boxes = form.querySelectorAll(selector);
                function refresh() {
                    var checked = Array.prototype.filter.call(boxes, function (b) { return b.checked; }).length;
                    boxes.forEach(function (b) { b.disabled = <?php echo $disabled ? 'true' : 'false'; ?> || (!b.checked && checked >= max); });
                }
                boxes.forEach(function (b) { b.addEventListener('change', refresh); });
                refresh();
            }
            capGroup('.interest-opt', 3);
            capGroup('.program-opt', 5);
        })();
        </script>

        <div class="d-flex align-items-center gap-3 mt-4">
            <button type="submit" class="btn btn-sked"<?php echo $dis; ?>>
                <i class="bi bi-save me-1"></i> Save KK Profile
            </button>
        </div>
    </form>
    <?php
}
