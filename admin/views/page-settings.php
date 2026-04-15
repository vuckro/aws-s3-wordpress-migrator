<?php
/**
 * Settings tab — Transform rule builder + import options reference.
 *
 * @package WaasKitS3Migrator
 */

defined( 'ABSPATH' ) || exit;

$perf_saved = ! empty( $_GET['perf_saved'] );
?>
<?php if ( $perf_saved ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Réglages de performance enregistrés.', 'waaskit-s3-migrator' ); ?></p></div>
<?php endif; ?>

<div class="wks3m-panel">
	<h2><?php esc_html_e( 'Performance & stabilité', 'waaskit-s3-migrator' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Ajuste la vitesse de migration et la résilience aux erreurs réseau. Augmenter la concurrence accélère la migration mais sollicite davantage le serveur source et l\'hôte WordPress.', 'waaskit-s3-migrator' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="wks3m_save_performance" />
		<?php wp_nonce_field( 'wks3m_save_performance' ); ?>

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="wks3m-concurrency"><?php esc_html_e( 'Migrations parallèles', 'waaskit-s3-migrator' ); ?></label></th>
					<td>
						<input type="number" id="wks3m-concurrency" name="concurrency" min="1" max="6" value="<?php echo esc_attr( \WKS3M\Settings::concurrency() ); ?>" class="small-text" />
						<p class="description">
							<?php esc_html_e( 'Nombre d\'images téléchargées en même temps lors des migrations en masse. Défaut : 3. Max : 6. Augmente en cas de source S3/CDN rapide, réduis si le site est lent ou si la source renvoie des 429/503.', 'waaskit-s3-migrator' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wks3m-defer-thumbs"><?php esc_html_e( 'Thumbnails différés', 'waaskit-s3-migrator' ); ?></label></th>
					<td>
						<label>
							<input type="checkbox" id="wks3m-defer-thumbs" name="defer_thumbnails" value="1" <?php checked( \WKS3M\Settings::defer_thumbnails() ); ?> />
							<?php esc_html_e( 'Ne pas générer les tailles intermédiaires pendant la migration.', 'waaskit-s3-migrator' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Accélère fortement l\'import (30–50 % par grosse image). Les thumbnails sont générés plus tard via le bouton ci-dessous ou la commande `wp media regenerate --only-missing`. Aucune perte de qualité.', 'waaskit-s3-migrator' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wks3m-retries"><?php esc_html_e( 'Tentatives de téléchargement', 'waaskit-s3-migrator' ); ?></label></th>
					<td>
						<input type="number" id="wks3m-retries" name="download_retries" min="1" max="5" value="<?php echo esc_attr( \WKS3M\Settings::download_retries() ); ?>" class="small-text" />
						<p class="description">
							<?php esc_html_e( 'Nombre maximum de tentatives par image en cas de timeout ou d\'erreur 5xx (backoff exponentiel 1s / 2s / 4s…). Défaut : 3.', 'waaskit-s3-migrator' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>

		<?php submit_button( __( 'Enregistrer', 'waaskit-s3-migrator' ) ); ?>
	</form>

	<hr />

	<h3><?php esc_html_e( 'Finaliser les thumbnails différés', 'waaskit-s3-migrator' ); ?></h3>
	<?php
	$pending = \WKS3M\Importer::pending_thumbnails_ids( 20000 );
	$pending_count = count( $pending );
	?>
	<p>
		<?php
		printf(
			/* translators: %d: number of attachments awaiting thumbnail generation */
			esc_html__( '%d attachment(s) importé(s) sans thumbnails.', 'waaskit-s3-migrator' ),
			(int) $pending_count
		);
		?>
	</p>
	<p>
		<button type="button" class="button button-secondary" id="wks3m-finalize-thumbs" <?php disabled( 0, $pending_count ); ?>>
			<?php esc_html_e( 'Générer les thumbnails manquants', 'waaskit-s3-migrator' ); ?>
		</button>
		<button type="button" class="button" id="wks3m-finalize-stop" hidden><?php esc_html_e( 'Stop', 'waaskit-s3-migrator' ); ?></button>
		<span class="spinner" id="wks3m-finalize-spinner" style="float:none;"></span>
	</p>
	<div id="wks3m-finalize-progress" class="wks3m-progress" hidden>
		<div class="wks3m-progress-bar"><span></span></div>
		<p class="wks3m-progress-label"></p>
	</div>
	<p class="description">
		<?php esc_html_e( 'Alternative : lance `wp media regenerate --only-missing` via WP-CLI si disponible.', 'waaskit-s3-migrator' ); ?>
	</p>
</div>

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
		<li><strong><?php esc_html_e( 'Dry-run', 'waaskit-s3-migrator' ); ?></strong> — <?php esc_html_e( 'simule l\'import sans rien télécharger ni écrire.', 'waaskit-s3-migrator' ); ?></li>
		<li><strong><?php esc_html_e( 'Remplacer URLs après import', 'waaskit-s3-migrator' ); ?></strong> — <?php esc_html_e( 'enchaîne automatiquement le remplacement dans le contenu des articles.', 'waaskit-s3-migrator' ); ?></li>
	</ul>
	<p class="description">
		<?php esc_html_e( 'Pour modifier les ALT ou les titres avant import (ex : remplir les ALT vides, nettoyer des placeholders), utilise l\'outil Transform ci-dessus.', 'waaskit-s3-migrator' ); ?>
	</p>
</div>
