<?php
/**
 * Settings tab view — import behaviour info + Search & Replace tool.
 *
 * @package WaasKitS3Migrator
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wks3m-panel">
	<h2><?php esc_html_e( 'Options d\'import', 'waaskit-s3-migrator' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Ces options se règlent depuis la barre d\'action de l\'onglet File d\'attente. Elles s\'appliquent à chaque import (unitaire ou en masse).', 'waaskit-s3-migrator' ); ?>
	</p>
	<ul class="wks3m-options-summary">
		<li><strong><?php esc_html_e( 'Mode dry-run', 'waaskit-s3-migrator' ); ?></strong> — <?php esc_html_e( 'simule l\'import sans rien télécharger ni écrire. Idéal pour un premier check.', 'waaskit-s3-migrator' ); ?></li>
		<li><strong><?php esc_html_e( 'Remplacer les URLs dans les articles après import', 'waaskit-s3-migrator' ); ?></strong> — <?php esc_html_e( 'enchaîne automatiquement le remplacement des URLs distantes par les URLs locales dans le contenu des articles affectés.', 'waaskit-s3-migrator' ); ?></li>
		<li><strong><?php esc_html_e( 'Utiliser l\'ALT comme titre', 'waaskit-s3-migrator' ); ?></strong> — <?php esc_html_e( 'si un ALT est détecté pour l\'image, il devient le post_title de l\'attachment (plus descriptif que le nom de fichier pour le SEO). Fallback au titre dérivé si ALT vide.', 'waaskit-s3-migrator' ); ?></li>
		<li><strong><?php esc_html_e( 'Remplir les ALT vides avec le titre', 'waaskit-s3-migrator' ); ?></strong> — <?php esc_html_e( 'si aucun ALT n\'a été détecté, le titre dérivé du filename est utilisé comme ALT de secours.', 'waaskit-s3-migrator' ); ?></li>
	</ul>
</div>

<div class="wks3m-panel">
	<h2><?php esc_html_e( 'Nettoyage : Search & Replace', 'waaskit-s3-migrator' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Remplace une chaîne dans les ALTs et/ou titres détectés. Utile pour nettoyer des placeholders parasites (ex. "XXX") avant d\'importer. La modification s\'applique à la file d\'attente et, si la case correspondante est cochée, aux attachments déjà importés dans la Media Library.', 'waaskit-s3-migrator' ); ?>
	</p>

	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row"><label for="wks3m-sr-find"><?php esc_html_e( 'Rechercher', 'waaskit-s3-migrator' ); ?></label></th>
				<td><input type="text" id="wks3m-sr-find" class="regular-text" value="" placeholder="XXX" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="wks3m-sr-replace"><?php esc_html_e( 'Remplacer par', 'waaskit-s3-migrator' ); ?></label></th>
				<td>
					<input type="text" id="wks3m-sr-replace" class="regular-text" value="" />
					<p class="description"><?php esc_html_e( 'Laisse vide pour simplement supprimer la chaîne.', 'waaskit-s3-migrator' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Champs cibles', 'waaskit-s3-migrator' ); ?></th>
				<td>
					<label><input type="checkbox" class="wks3m-sr-field" value="alt" checked /> <?php esc_html_e( 'ALT', 'waaskit-s3-migrator' ); ?></label>
					<label style="margin-left:1em;"><input type="checkbox" class="wks3m-sr-field" value="title" /> <?php esc_html_e( 'Titre dérivé', 'waaskit-s3-migrator' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Propagation', 'waaskit-s3-migrator' ); ?></th>
				<td>
					<label><input type="checkbox" id="wks3m-sr-update-attachments" checked /> <?php esc_html_e( 'Mettre à jour aussi les ALT des attachments déjà importés (postmeta _wp_attachment_image_alt).', 'waaskit-s3-migrator' ); ?></label>
				</td>
			</tr>
		</tbody>
	</table>

	<p>
		<button type="button" class="button" id="wks3m-sr-preview-btn"><?php esc_html_e( 'Aperçu', 'waaskit-s3-migrator' ); ?></button>
		<button type="button" class="button button-primary" id="wks3m-sr-apply-btn" disabled><?php esc_html_e( 'Appliquer', 'waaskit-s3-migrator' ); ?></button>
		<span class="spinner" id="wks3m-sr-spinner" style="float:none;"></span>
	</p>

	<div id="wks3m-sr-results" class="wks3m-sr-results" hidden>
		<div class="wks3m-sr-counts"></div>
		<table class="widefat striped" id="wks3m-sr-sample" hidden>
			<thead>
				<tr>
					<th style="width:80px"><?php esc_html_e( 'Ligne', 'waaskit-s3-migrator' ); ?></th>
					<th style="width:80px"><?php esc_html_e( 'Champ', 'waaskit-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'Avant', 'waaskit-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'Après (aperçu)', 'waaskit-s3-migrator' ); ?></th>
				</tr>
			</thead>
			<tbody></tbody>
		</table>
	</div>
</div>
