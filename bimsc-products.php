<?php
	$fields = [
		'group' => 'Nombre Principal del Producto',
		'bims_id' => 'ID BIMS',
		'name' => 'Nombre del Producto Variado',
		'category' => 'Categoria del Producto',
		'price' => 'Precio de Venta',
		'sale_price' => 'Precio de Oferta',
		'sku' => 'Codigo de Producto',
		'stock' => 'Cantidad en Stock',
		'description' => 'Descripcion',
		// 'image' => 'Imagen Principal',
		// 'images' => 'Imagenes Adicionales (Separado por coma)'
	];

	if(!empty($attributes)) {
		foreach($attributes as $attribute) {
			$fields[ "pa_".$attribute->attribute_name ] = '* '.$attribute->attribute_label.' (Campo Personalizado)';
		}
	}
?>
<div class="row mx-0">
	<div class="col-12">
		<?php if(!isset($target_file)): ?>
		<?php if(isset($result)): ?>
		<div class="alert <?=$result['count']>0?'alert-success':'alert-error'?>"><?=$result['count']>0?"Se han importado {$result['count']} productos.":"No se han importado productos"?></div>
		<?php endif; ?>
		<div class="card">
			<div class="card-header">
				<h4 class="card-title">Seleccione un archivo xlsx</h4>
			</div>
			<div class="card-body">
				<div class="upload_file">
					<form action="" method="POST" enctype="multipart/form-data">
						<div class="form-group">
							<input type="file" name="bimsc_file" accept=".xlsx">
						</div>
						<div class="form-group">
							<input type="submit" name="submit" class="btn btn-success" value="Importar Archivo">
						</div>
					</form>
				</div>
			</div>
		</div>
		<?php else: ?>
		<form action="" method="POST">
			<input type="hidden" name="bimsc_uploaded_file" value="<?=$target_file?>">
			<div class="table-responsive">
			<table class="table table-bordered table-hover">
				<thead>
					<tr>
						<?php foreach($sheetData[1] as $key => $val): ?>
						<th><?=$val?></th>
						<?php endforeach; ?>
					</tr>
					<tr>
						<?php foreach($sheetData[1] as $key => $val): ?>
							<td>
								<select name="bimsc_ifields[<?=$key?>]">
									<option value="">- Seleccione -</option>
									<?php foreach($fields as $field_name => $field_label): ?>
										<option value="<?=$field_name?>"><?=$field_label?></option>
									<?php endforeach; ?>
								</select>
							</td>
						<?php endforeach; unset($sheetData[1]); ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach($sheetData as $index => $row): ?>
						<tr>
							<?php foreach($row as $column => $value): ?>
							<td><?=$value?></td>
							<?php endforeach; ?>
						</tr>
					<?php if($index==5) break; endforeach; ?>
				</tbody>
			</table>
			</div>
			<div class="form-group">
				<input type="submit" name="submit" class="btn btn-success" value="Importar Archivo">
			</div>
		</form>
		<?php endif; ?>
	</div>
</div>
