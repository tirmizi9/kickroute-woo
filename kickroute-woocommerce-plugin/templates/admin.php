<div class="wrap">
<?php settings_errors() ?>
<form method="POST" action="options.php" style="margin-top: 20px;">
  <?php settings_fields('kickroute-settings-group') ?>
  <?php do_settings_sections('kickroute') ?>
  <?php submit_button('Speichern') ?>
</form>
