<?php
/**
 * Settings tab — Transform rule builder + import options reference.
 *
 * @package WaasKitS3Migrator
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wks3m-panel">
	<h2><?php esc_html_e( 'Transformer les ALT / Titres', 'waaskit-s3-migrator' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Construit une règle « Si… alors… » pour nettoyer en masse les ALTs et titres de la file d\'attente. Exemple : « Si l\'ALT contient "xxx", alors Copier le Titre ».', 'waaskit-s3-migrator' ); ?>
	</p>

	<table class="form-table" role="presentation" id="wks3m-tr-form">
		<tbody>
			<tr>
				<th scope="row"><label for="wks3m-tr-field"><?php esc_html_e( 'Champ', 'waaskit-s3-migrator' ); ?></label></th>
				<td>
					<select id="wks3m-tr-field">
						<option value="alt"><?php esc_html_e( 'ALT', 'waaskit-s3-migrator' ); ?></option>
						<option value="title"><?php esc_html_e( 'Titre dérivé', 'waaskit-s3-migrator' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label><?php esc_html_e( 'Condition', 'waaskit-s3-migrator' ); ?></label></th>
				<td>
					<select id="wks3m-tr-cond">
						<option value="contains"><?php esc_html_e( 'Contient', 'waaskit-s3-migrator' ); ?></option>
						<option value="equals"><?php esc_html_e( 'Égale', 'waaskit-s3-migrator' ); ?></option>
						<option value="empty"><?php esc_html_e( 'Est vide', 'waaskit-s3-migrator' ); ?></option>
					</select>
					<input type="text" id="wks3m-tr-cond-value" class="regular-text" placeholder="xxx" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label><?php esc_html_e( 'Action', 'waaskit-s3-migrator' ); ?></label></th>
				<td>
					<select id="wks3m-tr-action">
						<option value="from_title"><?php esc_html_e( 'Copier depuis le Titre', 'waaskit-s3-migrator' ); ?></option>
						<option value="from_alt"><?php esc_html_e( 'Copier depuis l\'ALT', 'waaskit-s3-migrator' ); ?></option>
						<option value="set_literal"><?php esc_html_e( 'Définir une valeur', 'waaskit-s3-migrator' ); ?></option>
						<option value="remove_substring"><?php esc_html_e( 'Supprimer la chaîne', 'waaskit-s3-migrator' ); ?></option>
						<option value="clear"><?php esc_html_e( 'Vider', 'waaskit-s3-migrator' ); ?></option>
					</select>
					<input type="text" id="wks3m-tr-action-value" class="regular-text" placeholder="" hidden />
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Propagation', 'waaskit-s3-migrator' ); ?></th>
				<td>
					<label><input type="checkbox" id="wks3m-tr-update-attachments" checked />
						<?php esc_html_e( 'Mettre à jour aussi les attachments déjà importés (postmeta ALT / post_title).', 'waaskit-s3-migrator' ); ?>
					</label>
				</td>
			</tr>
		</tbody>
	</table>

	<p>
		<button type="button" class="button" id="wks3m-tr-preview-btn"><?php esc_html_e( 'Aperçu', 'waaskit-s3-migrator' ); ?></button>
		<button type="button" class="button button-primary" id="wks3m-tr-apply-btn" disabled><?php esc_html_e( 'Appliquer', 'waaskit-s3-migrator' ); ?></button>
		<span class="spinner" id="wks3m-tr-spinner" style="float:none;"></span>
	</p>

	<div id="wks3m-tr-results" class="wks3m-tr-results" hidden>
		<div class="wks3m-tr-counts"></div>
		<table class="widefat striped" id="wks3m-tr-sample" hidden>
			<thead>
				<tr>
					<th style="width:80px"><?php esc_html_e( 'ID', 'waaskit-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'Avant', 'waaskit-s3-migrator' ); ?></th>
					<th><?php esc_html_e( 'Après', 'waaskit-s3-migrator' ); ?></th>
				</tr>
			</thead>
			<tbody></tbody>
		</table>
	</div>
</div>

<div class="wks3m-panel">
	<h2><?php esc_html_e( 'Options d\'import', 'waaskit-s3-migrator' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Ces options se règlent dans la barre de la File d\'attente et s\'appliquent à chaque migration (unitaire ou en masse).', 'waaskit-s3-migrator' ); ?></p>
	<ul class="wks3m-options-summary">
		<li><strong><?php esc_html_e( 'Dry-run', 'waaskit-s3-migrator' ); ?></strong> — <?php esc_html_e( 'simule sans télécharger ni écrire.', 'waaskit-s3-migrator' ); ?></li>
		<li><strong><?php esc_html_e( 'Remplacer URLs après import', 'waaskit-s3-migrator' ); ?></strong> — <?php esc_html_e( 'enchaîne automatiquement le remplacement dans le contenu des articles.', 'waaskit-s3-migrator' ); ?></li>
		<li><strong><?php esc_html_e( 'ALT → Titre', 'waaskit-s3-migrator' ); ?></strong> — <?php esc_html_e( 'utilise l\'ALT détecté comme post_title de l\'attachment.', 'waaskit-s3-migrator' ); ?></li>
		<li><strong><?php esc_html_e( 'Titre → ALT (si vide)', 'waaskit-s3-migrator' ); ?></strong> — <?php esc_html_e( 'si aucun ALT n\'a été détecté, utilise le titre dérivé comme fallback.', 'waaskit-s3-migrator' ); ?></li>
	</ul>
</div>
