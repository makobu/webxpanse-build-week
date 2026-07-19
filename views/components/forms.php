<?php
/**
 * Form Component Library
 */

/**
 * Render a form input
 */
function renderFormInput($name, $label, $type = 'text', $value = '', $required = false, $attributes = []) {
    $requiredAttr = $required ? 'required' : '';
    $attrString = '';
    foreach ($attributes as $key => $val) {
        $attrString .= " $key=\"" . htmlspecialchars($val) . "\"";
    }
    ?>
    <div class="form-group">
        <label class="form-label" for="<?php echo htmlspecialchars($name); ?>">
            <?php echo htmlspecialchars($label); ?>
            <?php if ($required): ?><span style="color: red;">*</span><?php endif; ?>
        </label>
        <input 
            type="<?php echo htmlspecialchars($type); ?>" 
            class="form-control" 
            id="<?php echo htmlspecialchars($name); ?>"
            name="<?php echo htmlspecialchars($name); ?>" 
            value="<?php echo htmlspecialchars($value); ?>"
            <?php echo $requiredAttr; ?>
            <?php echo $attrString; ?>
        >
    </div>
    <?php
}

/**
 * Render a form textarea
 */
function renderFormTextarea($name, $label, $value = '', $required = false, $rows = 5) {
    $requiredAttr = $required ? 'required' : '';
    ?>
    <div class="form-group">
        <label class="form-label" for="<?php echo htmlspecialchars($name); ?>">
            <?php echo htmlspecialchars($label); ?>
            <?php if ($required): ?><span style="color: red;">*</span><?php endif; ?>
        </label>
        <textarea 
            class="form-control" 
            id="<?php echo htmlspecialchars($name); ?>"
            name="<?php echo htmlspecialchars($name); ?>" 
            rows="<?php echo $rows; ?>"
            <?php echo $requiredAttr; ?>
        ><?php echo htmlspecialchars($value); ?></textarea>
    </div>
    <?php
}

/**
 * Render a form select
 */
function renderFormSelect($name, $label, $options, $value = '', $required = false) {
    $requiredAttr = $required ? 'required' : '';
    ?>
    <div class="form-group">
        <label class="form-label" for="<?php echo htmlspecialchars($name); ?>">
            <?php echo htmlspecialchars($label); ?>
            <?php if ($required): ?><span style="color: red;">*</span><?php endif; ?>
        </label>
        <select 
            class="form-control" 
            id="<?php echo htmlspecialchars($name); ?>"
            name="<?php echo htmlspecialchars($name); ?>"
            <?php echo $requiredAttr; ?>
        >
            <?php foreach ($options as $optValue => $optLabel): ?>
                <option value="<?php echo htmlspecialchars($optValue); ?>" 
                    <?php echo $value == $optValue ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($optLabel); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php
}
