<script type="text/javascript">
	var bimsTable = null;
	var wcTable = null;
	(function($) {
		$(document).ready(function() {
			bimsTable = $("#mainTable").DataTable();
			wcTable = $("#mainTable2").DataTable();
			$("#sync").click(function() {
				if(confirm("¿Estás seguro de que deseas sincronizar los productos con BIMS?")) {
					sync();
				}
			});

			function sync(sid, offset) {
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'bimsc_sync_products',
						sid: sid,
						offset: offset
					},
					dataType: 'json',
					success: function(response) {
						if(response.count > 0) {
							for(var k in response.products) {
								let product = response.products[k];
								bimsTable.rows( 
									function(idx, data, node) { 
										return data[2] == product[2]
									}
								).remove().draw();

								bimsTable.row.add(product).draw();
							}
							sync(response.sid, response.offset);
						}
					}
				});
			}
		});

	})(jQuery);
</script>
<style type="text/css">
	div.dt-container select.dt-input {
		width: 80px;
	}
</style>
<button id="sync" class="btn btn-large btn-success">Descargar Productos de BIMS</button>
<h2>Productos en BIMS</h2>
<table id="mainTable" class="table table-bordered table-hover mt-2">
	<thead>
		<tr>
			<th>ID WC</th>
			<th>ID WC Padre</th>
			<th>ID BIMS</th>
			<th>Nombre</th>
			<th>Categoría</th>
			<th>Tipo</th>
			<?php foreach($attributes as $attribute): ?>
				<th><?=$attribute->attribute_label?></th>
			<?php endforeach; ?>
		</tr>
	</thead>
	<tbody>
		<?php 
			if(!empty($bims_products)):
				foreach($bims_products as $product): 
					$product = json_decode($product->text); 
		?>
			<tr>
				<td><?=$product->Product->wc_id?></td>
				<td><?=$product->Product->wc_parent_id?></td>
				<td><?=$product->Product->id?></td>
				<td><?=$product->Product->name?></td>
				<td><?=$product->Ptype->name?></td>
				<td>BIMS</td>
			<?php foreach($attributes as $attribute): ?>
				<td><?=!empty($product->Product->attrs->{$attribute->attribute_name})?$product->Product->attrs->{$attribute->attribute_name}:null?></td>
			<?php endforeach; ?>
			</tr>
			<?php endforeach; ?>
		<?php endif; ?>
	</tbody>
</table>
<?php if(1==2): ?>
<table id="mainTable2" class="table table-bordered table-hover mt-2">
	<thead>
		<tr>
			<th class="text-center" colspan="7">Productos en E-Commerce</th>
		</tr>
		<tr>
			<th>ID WC</th>
			<th>ID WC Padre</th>
			<th>ID BIMS</th>
			<th>Nombre</th>
			<th>Categoría</th>
			<th>Tipo</th>
			<th>Opciones</th>
		</tr>
	</thead>
	<tbody>
		<?php foreach($products as $product): ?>
			<tr>
				<td><?=$product->get_id()?></td>
				<td><?=$product->get_parent_id()?></td>
				<td><?=$product->get_meta('_bims_id')?></td>
				<td><?=$product->get_name()?></td>
				<td>
					<?php
						if($product->get_type()=='variation') {
							$parent = wc_get_product($product->get_parent_id());
							$terms = get_the_terms($parent->get_id(), 'product_cat');
							if($terms) {
								$term = array_pop($terms);
								echo $term->name;
							}
						} else {							
							$terms = get_the_terms($product->get_id(), 'product_cat');
							if($terms) {
								$term = array_pop($terms);
								echo $term->name;
							}
						}
					?>
				</td>
				<td>
					<?=__(ucfirst($product->get_type()), 'woocommerce')?>
				</td>
				<td></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
<?php endif; ?>