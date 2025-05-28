<form action="" method="post">
	<div class="container-fluid">
		<div class="row">
			<div class="col-12">
				<h2>Configuración Principal</h2>
			</div>
			<div class="col-6">
				<div class="form-group">
					<label>API Endpoint URL</label>
					<input name="bimsc_url" type="text" value="<?php echo get_option('bimsc_url'); ?>" class="form-control" placeholder="https://bims.app">
				</div>
			</div>
			<div class="col-6">
				<div class="form-group">
					<label>Codigo SaaS</label>
					<input name="bimsc_tenant" type="text" value="<?php echo get_option('bimsc_tenant'); ?>" class="form-control" placeholder="miempresa123">
				</div>
			</div>
			<div class="col-6">
				<div class="form-group">
					<label>Usuario</label>
					<input name="bimsc_user" type="text" value="<?php echo get_option('bimsc_user'); ?>" class="form-control" placeholder="Usuario">
				</div>
			</div>
			<div class="col-6">
				<div class="form-group">
					<label>Contraseña</label>
					<input name="bimsc_password" type="password" value="<?php echo get_option('bimsc_password'); ?>" class="form-control">
				</div>
			</div>
		</div>
		<?php if(!empty(get_option('bimsc_url'))): ?>
		<div class="row">
			<div class="col-12">
				<h2>Información de BIMS</h2>
			</div>
			<div class="col-3">
				<div class="form-group">
					<label>Empresa</label>
					<select name="bimsc_company_id" class="form-control">
						<option disabled selected value="">Cargando ...</option>
					</select>
				</div>
			</div>
			<div class="col-3">
				<div class="form-group">
					<label>Sucursal</label>
					<select name="bimsc_agency_id" class="form-control">
						<option disabled selected value="">Cargando ...</option>
					</select>
				</div>
			</div>
			<div class="col-3">
				<div class="form-group">
					<label>Punto de Venta</label>
					<select name="bimsc_posale_id" class="form-control">
						<option disabled selected value="">Cargando ...</option>
					</select>
				</div>
			</div>
			<div class="col-3">
				<div class="form-group">
					<label>Moneda</label>
					<select name="bimsc_currency_id" class="form-control">
						<option disabled selected value="">Cargando ...</option>
					</select>
				</div>
			</div>
			<div class="col-3">
				<div class="form-group">
					<label>Campo CI</label>
					<select name="bimsc_docid_field" class="form-control">
						<option value="">- Seleccione -</option>
						<?php foreach($checkout_fields['billing'] as $key => $val): ?>
							<option <?php echo $key==get_option('bimsc_docid_field') ? 'selected' : ''; ?> value="<?php echo $key; ?>"><?php echo __($val['label']); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>
			<div class="col-3">
				<div class="form-group">
					<label>ID Producto para Envíos</label>
					<input name="bimsc_shipping_product_id" type="text" value="<?php echo get_option('bimsc_shipping_product_id'); ?>" class="form-control" placeholder="ID Producto para el concepto de Envío">
				</div>
			</div>
			<div class="col-3">
				<div class="form-group">
					<label>Almacenes Habilitados</label>
					<select id="warehouses" name="bimsc_warehouses[]" multiple class="form-control">
						<option disabled selected value="">Cargando ...</option>
					</select>
				</div>
			</div>
			<div class="col-3">
				<div class="form-group">
					<label>Catálogo de Productos</label>
					<select id="catalogs" name="bimsc_catalogs[]" class="form-control">
						<option disabled selected value="">Cargando ...</option>
					</select>
				</div>
			</div>
			<div class="col-12"><hr /></div>
			<?php foreach($roles as $key => $name): ?>
			<div class="col-3">
				<div class="form-group">
					<label><?=$name?></label>
					<select id="pricing_<?=$key?>" name="bimsc_roles[<?=$key?>]" class="pricings form-control">
						<option disabled selected value="">Cargando ...</option>
					</select>
				</div>
			</div>
			<?php endforeach; ?>
			<div class="col-12"><hr /></div>
			<?php foreach($pms as $key => $name): ?>
			<div class="col-3">
				<div class="form-group">
					<label><?=$name?></label>
					<select id="pm_<?=$key?>" name="bimsc_pms[<?=$key?>]" class="pms form-control">
						<option disabled selected value="">Cargando ...</option>
					</select>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
		<div class="row">
			<div class="col-12">
				<input type="submit" value="Guardar" class="btn btn-success">
			</div>
		</div>
	</div>
</form>
<hr />
<script type="text/javascript">
	var baseUrl = '<?php echo get_bloginfo('url'); ?>';
	var warehouses = '<?php echo get_option('bimsc_warehouses'); ?>';
	var catalogs = '<?php echo get_option('bimsc_catalogs'); ?>';

	(function($) {
		$(document).ready(function() {
			$("#btnSync").click(function(event) {
				$(".bprogress-bar").val(0);
				bsync();
				event.preventDefault();
			});
			<?php if(!empty(get_option('bimsc_url'))): ?>
			$.ajax({
				url: baseUrl+'/?wc-api=bims_company&pw=<?php echo md5(get_option('bimsc_password')); ?>',
				type: 'POST',
				dataType: 'json',
			})
			.done(function(data) {
				$('select[name=bimsc_company_id]').find('option').remove();
				$('select[name=bimsc_agency_id]').find('option').remove();
				$('select[name=bimsc_posale_id]').find('option').remove();
				$('select[name=bimsc_currency_id]').find('option').remove();
				$('select.pricings').find('option').remove();
				$('select#warehouses').find('option').remove();
				$('select#catalogs').find('option').remove();
				$('select.pms').find('option').remove();
				for(var k in data.data.companies) {
					let company = data.data.companies[k];
					$('<option/>')
					  .val(company.Company.id)
					  .text(company.Company.name)
					  .appendTo('select[name=bimsc_company_id]');
				}
				for(var k in data.data.agencies) {
					let agencies = data.data.agencies[k];
					$('<option/>')
					  .val(agencies.Agency.id)
					  .text(agencies.Agency.name)
					  .appendTo('select[name=bimsc_agency_id]');
				}
				for(var k in data.data.posales) {
					let posale = data.data.posales[k];
					$('<option/>')
					  .val(posale.Posale.id)
					  .text(posale.Posale.name)
					  .appendTo('select[name=bimsc_posale_id]');
				}
				for(var k in data.data.currencies) {
					let currency = data.data.currencies[k];
					$('<option/>')
					  .val(currency.Currency.id)
					  .text(currency.Currency.name)
					  .appendTo('select[name=bimsc_currency_id]');
				}
				for(var k in data.data.warehouses) {
					let warehouse = data.data.warehouses[k];
					$('<option/>')
					  .val(warehouse.Warehouse.id)
					  .text(warehouse.Warehouse.name)
					  .appendTo('select#warehouses');
				}
				$('<option/>')
					  .val(null)
					  .text("- Ninguno -")
					  .appendTo('select#catalogs');
				for(var k in data.data.catalogs) {
					let catalog = data.data.catalogs[k];
					$('<option/>')
					  .val(catalog.Catalog.id)
					  .text(catalog.Catalog.name)
					  .appendTo('select#catalogs');
				}

				for(var k in data.data.pricings) {
					let pricing = data.data.pricings[k];
					$('<option/>')
					  .val(pricing.Pricing.id)
					  .text(pricing.Pricing.name)
					  .appendTo('select.pricings');
				}

				$('<option/>')
					  .val(null)
					  .text("- Predeterminado -")
					  .appendTo('select.pms');

				for(var k in data.data.payment_methods) {
					let pricing = data.data.payment_methods[k];
					$('<option/>')
					  .val(pricing.PaymentMethod.id)
					  .text(pricing.PaymentMethod.name)
					  .appendTo('select.pms');
				}

				$("select[name=bimsc_company_id]").val(<?php echo !empty(get_option('bimsc_company_id')) ? get_option('bimsc_company_id') : ''; ?>);
				$("select[name=bimsc_agency_id]").val(<?php echo !empty(get_option('bimsc_agency_id')) ? get_option('bimsc_agency_id') : ''; ?>);
				$("select[name=bimsc_posale_id]").val(<?php echo !empty(get_option('bimsc_posale_id')) ? get_option('bimsc_posale_id') : ''; ?>);
				$("select[name=bimsc_currency_id]").val(<?php echo !empty(get_option('bimsc_currency_id')) ? get_option('bimsc_currency_id') : ''; ?>);
				$("select[name=user_pricing]").val(<?php echo !empty(get_option('bimsc_user_pricing')) ? get_option('bimsc_user_pricing') : ''; ?>);
				if(warehouses.length > 0) {
					$.each(warehouses.split(","), function(i,e){
					    $("select#warehouses option[value='" + e + "']").prop("selected", true);
					});
				}
				if(catalogs.length > 0) {
					$.each(catalogs.split(","), function(i,e){
					    $("select#catalogs option[value='" + e + "']").prop("selected", true);
					});
				}
				<?php if(!empty(unserialize(get_option('bimsc_roles')))): ?>
				<?php foreach( unserialize(get_option('bimsc_roles')) as $key => $val ): ?>
					$("select#pricing_<?=$key?>").val(<?=$val?>);
				<?php endforeach; ?>
				<?php endif; ?>
				<?php if(!empty(unserialize(get_option('bimsc_pms')))): ?>
				<?php foreach( unserialize(get_option('bimsc_pms')) as $key => $val ): ?>
					$("select#pm_<?=$key?>").val(<?=$val?>);
				<?php endforeach; ?>
				<?php endif; ?>
				$("select").selectize();
			});
			<?php endif; ?>
		});

		function bsync() {
			var cursor = 0;
			var products = 0;
			$(".bpr").show();

			$.ajax({
				url: baseUrl+'/?wc-api=sync',
				type: 'POST',
				dataType: 'json',
			})
			.done(function(data) {
				if(data.status=='success') {
					$(".bprogress-bar").val(data.cursor);
					$(".bprogress-bar").attr('max', data.progress);
					bsync();
				} else {
					$(".bprogress-bar").val($(".bprogress-bar").attr('max'));
					$(".bpr").hide();
					alert('Finalizado');
				}
			});
		}
	})(jQuery);
</script>
<?php if(!empty(get_option('bimsc_url'))): ?>
<div class="container-fluid">
	<div class="row">
		<div class="alert alert-info">
			Para sincronizar los productos, debes ejecutar vía cURL la siguiente URL: <b><?php echo get_bloginfo('url'); ?>/wc-api/syncfb</b><br />
			Para automatizar el proceso, se recomienda el uso de <b>cronjobs</b>.
		</div>
		<!--div class="col-12">
			<a id="btnSync" class="btn btn-primary" href="#">Sincronizar Productos (WC a BIMS)</a>
		</div!-->
		<div class="col-3 bpr" style="display: none;">
			<section>
				<span class="spinner is-active"></span>
				<progress class="woocommerce-exporter-progress bprogress-bar" max="100" value="0"></progress>
			</section>
		</div>
	</div>
</div>
<?php endif; ?>