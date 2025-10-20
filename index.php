<?php
include_once('vendor/autoload.php');
use PhpOffice\PhpSpreadsheet\IOFactory;
use WP_CLI\Utils;
/* 
Plugin Name: Bims Sync
Description: Plugin para la conexión con el Sistema BIMS
Version: 1.5.2
Author: Enrique Benitez (mod)
*/
if ( ! class_exists( 'WC_Countries' ) ) {
    include_once(WP_PLUGIN_DIR.'/woocommerce/includes/class-wc-countries.php');
}

if ( ! class_exists( 'WC_Session' ) ) {
    include_once( WP_PLUGIN_DIR . '/woocommerce/includes/abstracts/abstract-wc-session.php' );
}

if( ! class_exists( 'BimsC_Shopify' ) ) {
	include_once( 'shopify/shopify.php' );
}

if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

	function bimsc_setup_menu(){
	    add_menu_page( 'BIMS', 'BIMS', 'manage_options', 'bimsc' );
	    add_submenu_page(
	        'bimsc',         // Slug del menú padre
	        'Configuración', // Título de la página
	        'Configuración', // Título del submenú
	        'manage_options', // Capacidad necesaria
	        'bimsc_config',  // Slug del submenú
	        'bimsc_menu_config'   // Función que muestra el contenido de la página de Configuración
	    );

	    /*add_submenu_page(
	        'bimsc',         // Slug del menú padre
	        'Productos', // Título de la página
	        'Productos', // Título del submenú
	        'edit_shop_orders', // Capacidad necesaria
	        'bimsc_products',  // Slug del submenú
	        'bimsc_menu_products'   // Función que muestra el contenido de la página de Configuración
	    );*/

	    remove_submenu_page('bimsc', 'bimsc');
	}
	
	function bims_front($hook) {
		if( strpos($hook, "bims_page") !== false ) {			
			wp_enqueue_style('bootstrap4', 'https://stackpath.bootstrapcdn.com/bootstrap/4.1.1/css/bootstrap.min.css');
			wp_enqueue_style('selectize1', 'https://cdnjs.cloudflare.com/ajax/libs/selectize.js/0.13.3/css/selectize.bootstrap4.min.css');
			wp_enqueue_style('selectize2', 'https://cdnjs.cloudflare.com/ajax/libs/selectize.js/0.13.3/css/selectize.css');
			// wp_enqueue_script( 'boot1','https://code.jquery.com/jquery-3.3.1.min.js', array('jquery'));
			wp_enqueue_script( 'boot2','https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.3/umd/popper.min.js', array('jquery'));
			wp_enqueue_script( 'boot3','https://stackpath.bootstrapcdn.com/bootstrap/4.1.1/js/bootstrap.min.js', array('jquery'));
			wp_enqueue_script( 'boot4','https://cdnjs.cloudflare.com/ajax/libs/selectize.js/0.13.3/js/standalone/selectize.js', array('jquery'));
			if($hook == 'bims_page_bimsc_products') {
				wp_enqueue_style('datatable', 'https://cdn.datatables.net/2.0.7/css/dataTables.dataTables.min.css');
				wp_enqueue_script( 'datatable', 'https://cdn.datatables.net/2.0.7/js/dataTables.min.js', array('jquery'));
			}
		}
	}

	function bimsc_menu_config(){
		global $wp_roles;

		if (!did_action('woocommerce_init')) {
        	do_action('woocommerce_init');
		}
		
		if (!WC()->cart) {
			WC()->initialize_cart();
		}

		WC()->session = new WC_Session_Handler;
		WC()->customer = new WC_Customer;
		
		
		try {
			$checkout_fields = WC()->checkout->get_checkout_fields();
		} catch (Exception $e) {
			$checkout_fields = [];
			error_log('BIMS: Error obteniendo checkout fields - ' . $e->getMessage());
		}

		$all_roles = $wp_roles->roles;
		$roles = [];
		foreach($all_roles as $key => $role) {
			$roles[$key] = $role['name'];
		}

		$pms = [];
		$pmsData = get_enabled_payment_methods_with_ids();
		foreach($pmsData as $pm) {
			$pms[ $pm['id'] ] = $pm['name'];
		}

	    $page_template = dirname(__FILE__) .  '/bimsc-menu.php';
	    include $page_template;
	}	

	function bimsc_get_category($nombre_categoria) {
	    // Obtener la categoría de producto
	    $categoria = get_term_by('name', $nombre_categoria, 'product_cat');

	    // Si la categoría no existe, crearla
	    if (!$categoria) {
	        $categoria_id = wp_insert_term(
	            $nombre_categoria, // Nombre de la categoría
	            'product_cat', // Taxonomía
	            array(
	                'description' => 'Descripción de la categoría ' . $nombre_categoria,
	                'slug'        => sanitize_title($nombre_categoria)
	            )
	        );

	        if (!is_wp_error($categoria_id)) {
	            $categoria = get_term_by('id', $categoria_id['term_id'], 'product_cat');
	        } else {
	            return false;
	        }
	    }

	    return $categoria;
	}

	function get_product_by_bims_id($id) {
		global $wpdb;

		$args = array(
		    'post_type'      => ['product', 'product_variation'],
		    'posts_per_page' => 1,
		    'meta_query'     => array(
		        array(
		            'key'     => '_bims_id',
		            'value'   => $id,
		            'compare' => '='
		        )
		    )
		);

		$query = new WP_Query( $args );

		if ( $query->have_posts() ) {
		    while ( $query->have_posts() ) {
		        $query->the_post();
		        $product = wc_get_product( get_the_ID() );
		        return $product;
		    }
		} else {
		    return false;
		}

		return false;
	}

	function proccess_uploaded_file($target_file) {
		$attributes = wc_get_attribute_taxonomies();
		$cfields = [];
		$importedProducts = 0;

		if(!empty($attributes)) {
			foreach($attributes as $attribute) {
				$cfields[ $attribute->attribute_name ] = $attribute->attribute_label;
			}
		}

		$reader = IOFactory::createReader('Xlsx');
		$reader->setReadDataOnly(true);
		$spreadsheet = $reader->load($target_file);
		$sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);

		$groupedProducts = [];
		$importFields = $_POST['bimsc_ifields'];
		foreach($importFields as $key => $val) {
			if(empty($val))
				unset($importFields[$key]);
		}

		$importFields = array_flip($importFields);
		$customAttributes = [];
		foreach($sheetData as $index => $row) {
			if($index==1)
				continue;
			if(empty($importFields['group']))
				continue;

			$groupedProducts[ $row[ $importFields['group'] ] ][] = $row;
		}

		foreach($groupedProducts as $main_name => $groupedProduct) {
			if(empty($main_name))
				continue;

			$child_product = get_product_by_bims_id($groupedProduct[0][ $importFields['bims_id'] ]);
			if(!empty($child_product)) {
				$product = get_product($child_product->get_parent_id());
			} else {
				$product = new WC_Product_Variable();
				$product->set_status('draft');
			}

			if(
				isset($importFields['description'])
				&&
				!empty($groupedProduct[0][ $importFields['description'] ])
			) {
				$product->set_description( $groupedProduct[0][ $importFields['description'] ] );
			}

			$product->set_name( $main_name );

			$category = bimsc_get_category($groupedProduct[0][ $importFields['category'] ]);
			if($category) {
				$product->set_category_ids( [$category->term_id] );
			}

			$pattributes = [];
			$image = null;

			foreach($groupedProduct as $row) {
				foreach($importFields as $import_key => $import_val) {
					if(
						strpos($import_key, "pa_") !== false
						&&
						!empty($row[ $importFields[$import_key] ])
					) {
						$attr_name = str_replace("pa_", "", $import_key);
						$pattributes[ $attr_name ][ $row[ $importFields[$import_key] ] ] = $row[ $importFields[$import_key] ];
					}
				}
			}

			$pattrs = [];
			$position = 0;
			foreach($pattributes as $attr_name => $attr_values) {
				$attribute = new WC_Product_Attribute();
            	$taxonomy = wc_attribute_taxonomy_name($attr_name);
            	$id = wc_attribute_taxonomy_id_by_name($attr_name);
            	$attribute->set_id( $id );
				$attribute->set_name( $taxonomy );
				$attribute->set_options( array_values($attr_values) );
				$attribute->set_position( $position++ );
				$attribute->set_visible( true );
				$attribute->set_variation( true ); // here it is
				$pattrs[] = $attribute;
			}

			$product->set_attributes( $pattrs );
			$product->save();
			
			foreach($groupedProduct as $row) {
				$vproduct = get_product_by_bims_id($row[ $importFields['bims_id'] ]);
				if(empty($vproduct)) {
					$vproduct = new WC_Product_Variation();
				}

				$vproduct->set_parent_id($product->get_id());
				$vproduct->set_regular_price( $row[ $importFields['price'] ] );
				if(isset($importFields['stock'])) {
					$vproduct->set_stock_quantity( $row[ $importFields['stock'] ] );
				}

				$attrs = [];
	            foreach ($importFields as $import_key => $import_val) {
	                if (strpos($import_key, "pa_") !== false) {
	                    $attr_name = str_replace("pa_", "", $import_key);
	                    $taxonomy = wc_attribute_taxonomy_name($attr_name);
	                    $attrs[$taxonomy] = sanitize_title($row[$importFields[$import_key]]);
	                }
	            }
	            
	            $vproduct->set_attributes($attrs);

				if(
					isset($importFields['bims_id'])
					&&
					!empty($row[ $importFields['bims_id'] ])
				) {
					$vproduct->update_meta_data('_bims_id', $row[ $importFields['bims_id'] ]);
				}
				$vproduct->set_name( $row[ $importFields['name'] ] );
				$vproduct->set_sku( $row[ $importFields['sku'] ] );
				$vproduct->set_status('draft');
				$vproduct->save();

				$importedProducts++;
			}
		}

		return [
			'count' => $importedProducts,
			'products' => $groupedProducts
		];
	}

	function bimsc_menu_products() {
		global $wpdb;
		$debug = false;

		if($debug) {

			$target_dir = dirname(__FILE__).'/uploads/';
			$target_file = $target_dir . 'ENTRADA 23-05.xlsx';

			$_POST['bimsc_uploaded_file'] = $target_file;
			$_POST['bimsc_ifields'] = [
			    'A' => 'bims_id',
			    'B' => '',
			    'C' => 'category',
			    'D' => '',
			    'E' => 'group',
			    'F' => 'pa_color',
			    'G' => '',
			    'H' => '',
			    'I' => 'name',
			    'J' => 'price',
			    'K' => '',
			    'L' => '',
			    'M' => '',
			    'N' => '',
			    'O' => 'pa_talle',
			];
		}

		if(!empty($_POST['bimsc_uploaded_file'])) {
			if($debug)
				echo '<pre>'.print_r($_POST, true).'</pre>';
			$result = proccess_uploaded_file($_POST['bimsc_uploaded_file']);
		}

		$attributes = wc_get_attribute_taxonomies();

		if(file_exists($target_file)) {
			$reader = IOFactory::createReader('Xlsx');
			$reader->setReadDataOnly(true);
			$spreadsheet = $reader->load($target_file);
			$sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
		}

		if(!empty($_FILES['bimsc_file'])) {
			$allowedExtensions = [
				'xlsx'
			];

			$ext = pathinfo($_FILES['bimsc_file']['name'], PATHINFO_EXTENSION);
			if(!in_array($ext, $allowedExtensions)) {
				echo 'El archivo no es válido';
				die();
			}

			if(!file_exists(dirname(__FILE__).'/uploads'))
				mkdir(dirname(__FILE__).'/uploads');

			$target_file = $target_dir . basename($_FILES["bimsc_file"]["name"]);
			if(move_uploaded_file($_FILES['bimsc_file']['tmp_name'], $target_file)) {
				$reader = IOFactory::createReader('Xlsx');
				$reader->setReadDataOnly(true);
				$spreadsheet = $reader->load($target_file);
				$sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
				
			}
		}

	    $page_template = dirname(__FILE__) .  '/bimsc-products.php';
	    include $page_template;
	}

	function get_enabled_payment_methods_with_ids() {
	    $enabled_methods = array();

	    $payment_gateways = WC_Payment_Gateways::instance()->payment_gateways();
	    
	    foreach ($payment_gateways as $gateway) {
	        if ($gateway->enabled === 'yes') {
	            $enabled_methods[] = array(
	                'name' => $gateway->title,
	                'id' => $gateway->id,
	            );
	        }
	    }

	    return $enabled_methods;
	}

	class BimsC {
		public $url;
		public $user;
		public $password;
		public $tenant;
		public $sid;
		public $checkout_fields;

		public $months = array(
			'enero' => "01",
			'febrero' => "02",
			'marzo' => "03",
			'abril' => "04",
			'mayo' => "05",
			'junio' => "06",
			'julio' => "07",
			'agosto' => "08",
			'septiembre' => "09",
			'octubre' => 10,
			'noviembre' => 11,
			'diciembre' => 12
		);

		public $times = array(
			'7:30 am a 10:30 am' => '07:30:00',
			'Inmediato (tiempo estimado 45-60 min.)' => "now",
			'10:30 am a 12:30 pm' => '10:30:00',
			'12:30 pm a 16:00 pm' => '12:30:00',
			'16:00 pm a 19:00 pm' => '16:00:00',
			'19:00 pm a 22:00 pm' => '19:00:00',
		);

	    public static function init() {
			$send_no_cache_headers = apply_filters('rest_send_nocache_headers', is_user_logged_in());
			if (!$send_no_cache_headers && !is_admin() && $_SERVER['REQUEST_METHOD'] == 'GET') {
			    $nonce = wp_create_nonce('wp_rest');
			    $_SERVER['HTTP_X_WP_NONCE'] = $nonce;
			}

	        $class = __CLASS__;
	        new $class;

	    }

         
		public function WooCommerce_Sync_CLI_Command() {
		    $this->receiveFromBimsNew();
		}

		public function FromShopify_CLI_Command() {
			$shopify = new BimsC_Shopify();
			$products = $shopify->listProductsByCollection(428931842297);
			// print_r($products); die();
			$wc_attributes_array = wc_get_attribute_taxonomies();
			$wc_attributes = [];
			foreach($wc_attributes_array as $wca) {
				$wc_attributes[ $wca->attribute_name ] = $wca;
			}

			foreach($products as $sproduct) {
				$productData = $shopify->getProduct($sproduct->id);
				$product = $this->get_product_by_shopify_id($sproduct->id);
				if(empty($product)) {
					$product = new WC_Product_Variable();
				}

				$product->set_name($productData->title);
				$product->set_sku($productData->sku);
				$product->set_description($productData->body_html);
				$product->set_stock_status('instock');

				$options = [];
				$pattrs = [];

				foreach($productData->options as $key => $option) {
					$options[ $key ] = $option->name;

		 			$taxonomy = wc_attribute_taxonomy_name($option->name);
		 			$wc_attribute = $wc_attributes[ str_replace('pa_',null,$taxonomy) ];
					$attribute = new WC_Product_Attribute();
					
					$attribute->set_id($wc_attribute->attribute_id);
		            $attribute->set_name($taxonomy);
		            $attribute->set_options($option->values);
		            $attribute->set_position($key+1);
		            $attribute->set_visible(true);
		            $attribute->set_variation(true);

		            $pattrs[] = $attribute;
				}

				$product->set_attributes($pattrs);
				$product->update_meta_data('_shopify_id', $productData->id);
				$product->save();
				//attributes 

				if(!empty($productData->variants)) {
					foreach($productData->variants as $variant) {
						$variation = $this->get_product_by_shopify_id($variant->id);
						if(empty($variation)) {
							$variation = new WC_Product_Variation();
						}

						$variation->set_name($variant->title);
						$variation->set_sku($variant->sku);
						$variation->set_description($variant->body_html);
						$variation->set_price($variant->price);
						$variation->set_regular_price($variant->price);
						$variation->set_manage_stock(true);
						$variation->set_stock_quantity($variant->inventory_quantity > 0 ? $variant->inventory_quantity : 1);
						$variation->set_parent_id($product->get_id());
						$variation->set_status('publish');

						$attrs = [];

						foreach($options as $index => $option) {
							$taxonomy = wc_attribute_taxonomy_name($option);
							$option_index = $index+1;
							$option_value = $variant->{"option$option_index"};

							$attrs[ $taxonomy ] = sanitize_title($option_value);
						}

						$variation->set_attributes($attrs);
						$variation->update_meta_data('_shopify_id', $variant->id);
						$variation->save();
					}
				}

				$product->save();
				$tags = explode(", ", $productData->tags);
				wp_set_object_terms($product->get_id(), $tags, 'product_tag');

				$image_url = wc_rest_upload_image_from_url($productData->image->src);

				if(
					!empty($image_url->error)
					||	
					!empty($image_url->errors)
					||
					is_a($image_url, 'WC_Error')
					||
					$image_url instanceof WC_Error
				) {
					$image_url = null;
				}

				if(!empty($image_url)) {
					$image_id = wc_rest_set_uploaded_image_as_attachment($image_url, $product->get_id());
					$product->set_image_id($image_id);
				}

				if(!empty($productData->images)) {
					$images = [];
					foreach($productData->images as $image) {
						$image_url = wc_rest_upload_image_from_url($image->src);
						if(!empty($image_url->error))
							continue;
						if(!empty($image_url->errors))
							continue;
						if (is_a($image_url, 'WC_Error'))
							continue;
						if ($image_url instanceof WC_Error)
							continue;
						$image_id = wc_rest_set_uploaded_image_as_attachment($image_url, $product->get_id());
						$images[] = $image_id;
					}

					if(!empty($images)) {
						$product->set_gallery_image_ids($images);
					}
				}


				$category_name = "Preview 25";

			    $category_data = array(
			        'name' => $category_name,
			        'slug' => sanitize_title($category_name),
			        'parent' => 0
			    );

			    $category_exists = term_exists($category_data['slug'], 'product_cat');

				if ($category_exists === 0 || $category_exists === null) {
			        $new_category = wp_insert_term($category_data['name'], 'product_cat', $category_data);
			        if (!is_wp_error($new_category)) {
			            $category_id = $new_category['term_id'];
			            wp_set_object_terms($product->get_id(), $category_id, 'product_cat');
			        }
				} else {
					$category = get_term_by('slug', $category_data['slug'], 'product_cat');
					wp_set_object_terms($product->get_id(), $category->term_id, 'product_cat');
				}


				$category_name = "Preview 25: {$productData->product_type}";

			    $category_data = array(
			        'name' => $category_name,
			        'slug' => sanitize_title($category_name),
			        'parent' => 0
			    );

			    $category_exists = term_exists($category_data['slug'], 'product_cat');

				if ($category_exists === 0 || $category_exists === null) {
			        $new_category = wp_insert_term($category_data['name'], 'product_cat', $category_data);
			        if (!is_wp_error($new_category)) {
			            $category_id = $new_category['term_id'];
			            wp_set_object_terms($product->get_id(), $category_id, 'product_cat');
			        }
				} else {
					$category = get_term_by('slug', $category_data['slug'], 'product_cat');
					wp_set_object_terms($product->get_id(), $category->term_id, 'product_cat');
				}
				
				$product->save(); 

				WP_CLI::line("Product {$productData->title} imported");
			}

			WP_CLI::line("All products imported");
		}

		private function get_product_by_shopify_id($id) {
			global $wpdb;

			$args = array(
			    'post_type'      => ['product', 'product_variation'],
			    'posts_per_page' => 1,
			    'meta_query'     => array(
			        array(
			            'key'     => '_shopify_id',
			            'value'   => $id,
			            'compare' => '='
			        )
			    )
			);

			$query = new WP_Query( $args );

			if ( $query->have_posts() ) {
			    while ( $query->have_posts() ) {
			        $query->the_post();
			        $product = wc_get_product( get_the_ID() );
			        return $product;
			    }
			} else {
			    return false;
			}

			return false;
		}

		public function WooCommerce_Albertina_Import( $args, $assoc_args ) {
		    if(empty($assoc_args['file']))
		        WP_CLI::error( 'Falta el argumento --file' );

		    $file = $assoc_args['file'];
		    $spreadsheet = IOFactory::load($file);
		    $worksheet = $spreadsheet->getActiveSheet();
		    $rows = $worksheet->toArray();

		    WP_CLI::success('Archivo cargado correctamente filas: '.count($rows));
		    $totalProducts = 0;
		    $excludeProducts = [];
		    $notExcludeProducts = [];
		    foreach($rows as $i => $row) {
		        if($i==0)
		            continue;

		        // $notExcludeProducts[ $row[1] ] = $row[1];
		        // print_r($row);
		        $product = wc_get_product( $row[2] );
		        if(empty($product))
		        	continue;
		        if(empty($row[0]) || $row[0] == "0") {
		        	$product->set_status('draft');
		        	$product->save();
		            continue;
		        }
		        WP_CLI::log( $product->get_name() );

		        // get meta _bims_id
		        $bims_id = $product->get_meta('_bims_id');
		        if(!empty($bims_id)) {
		            continue;
		        }

		        // set meta _bims_id
		        $product->update_meta_data( '_bims_id', $row[0] );
		        $product->save();
		        WP_CLI::success( $product->get_name()." se ha guardado." );
		        $totalProducts++;
		    }

		    /*foreach($excludeProducts as $product_id) {
		        if(in_array($product_id, $notExcludeProducts))
		            continue;

		        $product = wc_get_product($product_id);
		        $product->set_status('draft');
		        $product->save();
		    }*/

		    WP_CLI::success("Se han procesado {$totalProducts} productos.");
		}

		public function BimsC_CLI_Command() {
			$args = array(
			    'post_type' => array('product'),
			    'status' => 'publish',
			    'posts_per_page' => -1
			);


			$letras = array('A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z');
			$l = $letras;
			foreach ($l as $x => $l1) {
				foreach ($l as $y => $l2) {
					foreach ($l as $z => $l3) {
						$letras[] = $l1 . $l2 . $l3;
					}
				}
			}


			$xlsx = new PhpOffice\PhpSpreadsheet\Spreadsheet();	
			$sheet = $xlsx->getActiveSheet();

			$li = 0;
			$rowIndex = 1;
			$sheet->setCellValue("{$letras[$li++]}{$rowIndex}", 'ID BIMS');
			$sheet->setCellValue("{$letras[$li++]}{$rowIndex}", 'ID WC PADRE');
			$sheet->setCellValue("{$letras[$li++]}{$rowIndex}", 'ID WC VARIANTE');
			$sheet->setCellValue("{$letras[$li++]}{$rowIndex}", 'NOMBRE');


			$attributes = wc_get_attribute_taxonomies();

			foreach($attributes as $attribute) {
				$sheet->setCellValue("{$letras[$li++]}{$rowIndex}", $attribute->attribute_label);
			}



			$query = new WP_Query($args);

			$rowIndex++;

			if($query->have_posts()) {
				while ( $query->have_posts() ) {
			        $query->the_post();
			        $product_id = get_the_ID();
			        $product = wc_get_product($product_id);
			        WP_CLI::line($product->get_name());
					if($product->is_type('variable')) {
						$variations = $product->get_children();
						foreach($variations as $variation_id) {
							$li = 0;
							$variation = wc_get_product($variation_id);
							$sheet->setCellValue("{$letras[$li++]}{$rowIndex}", $variation->get_meta('_bims_id'));
							$sheet->setCellValue("{$letras[$li++]}{$rowIndex}", $product_id);
							$sheet->setCellValue("{$letras[$li++]}{$rowIndex}", $variation_id);
							$sheet->setCellValue("{$letras[$li++]}{$rowIndex}", $variation->get_name());

							foreach($attributes as $attribute) {
								$sheet->setCellValue("{$letras[$li++]}{$rowIndex}", $variation->get_attribute($attribute->attribute_name));
							}

							$rowIndex++;
						}
					}
				}
			}

			$writer = new PhpOffice\PhpSpreadsheet\Writer\Xlsx($xlsx);
			$writer->save('bims_products.xlsx');
		}

	    public function __construct() {

			require_once(__DIR__ . '/wp-config-env.php');

			$this->url = getenv('BIMSC_URL') ?: get_option('bimsc_url');
			$this->user = getenv('BIMSC_USER') ?: get_option('bimsc_user');
			$this->password = getenv('BIMSC_PASSWORD') ?: get_option('bimsc_password');
			$this->tenant = getenv('BIMSC_TENANT') ?: get_option('bimsc_tenant');

			if (empty($this->url) || empty($this->user) || empty($this->password)) {
				error_log('BIMSC: Credenciales no configuradas. Verifica .env o configuración de WordPress.');
			}

	    	// date_default_timezone_set("America/Asuncion");
			$send_no_cache_headers = apply_filters('rest_send_nocache_headers', is_user_logged_in());
			if (!$send_no_cache_headers && !is_admin() && $_SERVER['REQUEST_METHOD'] == 'GET') {
			    $nonce = wp_create_nonce('wp_rest');
			    $_SERVER['HTTP_X_WP_NONCE'] = $nonce;
			}
			
			add_filter( 'woocommerce_get_stock_html', array($this, 'filter_woocommerce_stock_html'), 10, 3 ); 
			add_action( 'admin_enqueue_scripts', 'bims_front' );
			add_action( 'woocommerce_api_bims_update', array( $this, 'receiveJson' ) );
			add_action( 'woocommerce_api_sync', array( $this, 'syncProducts' ) );
			add_action( 'woocommerce_api_bims_company', array( $this, 'syncBIMS' ) );
			add_action( 'woocommerce_api_syncfb', array( $this, 'receiveFromBims' ) );
			add_action( 'woocommerce_api_bims_notify', array( $this, 'notifyToBims' ) );
			add_action( 'woocommerce_api_getbysku', array( $this, 'getBySKU' ) );
			add_action( 'woocommerce_api_test', array( $this, 'testtest' ) );
			add_filter( 'woocommerce_product_get_price', array( $this, 'custom_price' ), 10, 2);
			add_filter( 'woocommerce_product_variation_get_price', array( $this, 'custom_price' ), 10, 2 );
			add_filter( 'woocommerce_product_get_regular_price', array( $this, 'custom_price' ), 10, 2 );
			add_filter( 'woocommerce_product_get_sale_price', array( $this, 'custom_price' ), 10, 2 );
			add_action( 'admin_menu', 'bimsc_setup_menu' );
			// add_filter( 'manage_product_posts_columns', 'agregar_columna_fecha_modificacion', 20 );
			// add_action( 'manage_product_posts_custom_column', 'mostrar_valor_columna_fecha_modificacion', 20, 2 );
			add_action( 'woocommerce_api_bimsc_send_order', array( $this, 'wcbims_send_order') );
			if( defined( 'WP_CLI' ) && WP_CLI )  {
				WP_CLI::add_command( 'from_shopify', array( $this, 'FromShopify_CLI_Command' ));
    			WP_CLI::add_command( 'bims_sync', array( $this, 'WooCommerce_Sync_CLI_Command' ));
    			WP_CLI::add_command( 'bims_test', array( $this, 'testtest' ) );
    			WP_CLI::add_command( 'bims_export', array( $this, 'BimsC_CLI_Command' ) );
    			WP_CLI::add_command( 'albertina_import', array( $this, 'WooCommerce_Albertina_Import') );
			}

			// add_action('wp_ajax_wcbims_send_order', array( $this, 'wcbims_send_order') );

			function agregar_columna_fecha_modificacion( $columns ) {
			    $columns['fecha_modificacion'] = __( 'Fecha de modificación', 'woocommerce' );
			    return $columns;
			}

			function mostrar_valor_columna_fecha_modificacion( $column, $post_id ) {
			    if ( $column == 'fecha_modificacion' ) {
			        $post_modified = get_post_modified_time( 'G', true, $post_id );
			        $timezone = wp_timezone();

			        $date_time = new DateTime();
			        $date_time->setTimestamp( $post_modified );
			        /*if ( $timezone ) {
			            $date_time->setTimezone( $timezone );
			            date_default_timezone_set( $timezone->timezone );
			        }*/
			        echo esc_html( $date_time->format( "d/m/Y H:i:s") );
			    }
			}

			add_action( 'admin_head', 'agregar_estilos_listado_productos' );
			function agregar_estilos_listado_productos() {
			    echo '<style>
			        .wp-list-table {
			            max-height: 500px;
			            overflow: auto;
			        }
			        .column-fecha_modificacion {
			            width: 150px !important;
			        }
			    </style>';
			}

			add_filter( 'manage_edit-product_sortable_columns', 'agregar_orden_por_fecha_modificacion' );
			function agregar_orden_por_fecha_modificacion( $sortable_columns ) {
			    $sortable_columns['fecha_modificacion'] = 'post_modified';
			    return $sortable_columns;
			}

			add_filter( 'request', 'ordenar_por_fecha_modificacion' );
			function ordenar_por_fecha_modificacion( $vars ) {
			    if ( isset( $vars['orderby'] ) && 'post_modified' == $vars['orderby'] ) {
			        $vars = array_merge( $vars, array(
			            'orderby' => 'modified',
			            'order' => 'desc'
			        ) );
			    }
			    return $vars;
			}

			if(!empty($_POST)) {
				if(isset($_POST['bimsc_url']))
					update_option('bimsc_url', $_POST['bimsc_url']);

				if(isset($_POST['bimsc_user']))
					update_option('bimsc_user', $_POST['bimsc_user']);

				if(isset($_POST['bimsc_password']))
					update_option('bimsc_password', $_POST['bimsc_password']);

				if(isset($_POST['bimsc_company_id']))
					update_option('bimsc_company_id', $_POST['bimsc_company_id']);

				if(isset($_POST['bimsc_agency_id']))
					update_option('bimsc_agency_id', $_POST['bimsc_agency_id']);

				if(isset($_POST['bimsc_posale_id'])) // check
					update_option('bimsc_posale_id', $_POST['bimsc_posale_id']);

				if(isset($_POST['bimsc_currency_id']))
					update_option('bimsc_currency_id', $_POST['bimsc_currency_id']);

				if(isset($_POST['bimsc_docid_field']))
					update_option('bimsc_docid_field', $_POST['bimsc_docid_field']);

				if(isset($_POST['bimsc_tenant']))
					update_option('bimsc_tenant', $_POST['bimsc_tenant']);
				
				if(isset($_POST['bimsc_warehouses']))
					update_option('bimsc_warehouses', implode(",",$_POST['bimsc_warehouses']));

				if(isset($_POST['user_pricing']))
					update_option('bimsc_user_pricing', $_POST['user_pricing']);

				if(!empty($_POST['bimsc_roles'])) {
					update_option('bimsc_roles', serialize($_POST['bimsc_roles']));
				}

				if(!empty($_POST['bimsc_pms'])) {
					update_option('bimsc_pms', serialize($_POST['bimsc_pms']));
				}

				if(isset($_POST['bimsc_shipping_product_id']))
					update_option('bimsc_shipping_product_id', $_POST['bimsc_shipping_product_id']);

				if(isset($_POST['bimsc_catalogs']))
					update_option('bimsc_catalogs', implode(",",$_POST['bimsc_catalogs']));

				
			}

			// add_action( 'woocommerce_thankyou', array( $this, 'so_payment_complete' ) );
			add_action( 'woocommerce_api_remove_sku', array( $this, 'remove_sku' ) );
			// add_action( 'woocommerce_api_sync_orders', array( $this, 'sync' ) );
			add_action( 'woocommerce_order_status_changed', array( $this, 'bimsc_finish_order'), 10, 3 );
			add_action( 'admin_init', array( $this, 'init_bimsc_admin'));
			add_action( 'action_payment_complete', array($this, 'so_payment_complete') );
			// add_action('woocommerce_new_order', array($this, 'so_payment_complete'));
			// add_action( 'woocommerce_order_status_completed', array($this, 'bims_order_completed'));
		}

		// Hook para manejar la solicitud AJAX
		
		public function wcbims_send_order() {
		    check_ajax_referer('wcbims_nonce_action', 'nonce');

		    $order_id = intval($_POST['order_id']);
		    // $branch_id = sanitize_text_field($_POST['branch_id']);
		    $posale_id = $_POST['branch_id'];

			// mod
			$posale_id = get_post_meta($order_id, '_cashier', true);

			// check
		    $bims_id = $this->so_payment_complete($order_id, $posale_id);
		    if(!$bims_id) {
		    	wp_send_json_error(array('message' => 'Error al enviar la orden a BIMS'));
		    	exit;
		    }

		    // update_post_meta($order_id, '_bims_id', $bims_id);
		    update_post_meta($order_id, '_bims_branch', $branch_id);

		    wp_send_json_success(array('bims_id' => $bims_id));
		}

		public function sync_products_callback() {
			if(!current_user_can('administrator')) {
				die();
			}

			global $wpdb;

			$tabla_products = $wpdb->prefix.'bimsc_products';

			$wc_attributes = wc_get_attribute_taxonomies();
			$attributes = [];
			foreach($wc_attributes as $attribute) {
				$attributes[ $attribute->attribute_label ] = $attribute->attribute_name;
			}

			if(empty($_POST['sid'])) {
				$this->login();
			} else {
				$this->sid = $_POST['sid'];
			}
			$limit = 1000;
			$offset = !empty($_POST['offset']) ? $_POST['offset'] : 0;

			$company_id = get_option('bimsc_company_id');

			$catalogsData = get_option('bimsc_catalogs');
			$catalogs = array();
			$catalogsStr = "&";
			if(!empty($catalogsData)) {
				$catalogs = explode(",", $catalogsData);
				foreach($catalogs as $i => $catalog) {
					$catalogsStr .= "catalog[{$i}]={$catalog}&";
				}
			} else {
				$catalogsStr = "&catalog=";
			}
			/*if($offset > 1000) {
				$reply = [
					'sid' => $this->sid,
					'offset' => $offset + $limit,
					'products' => [],
					'count' => 0
				];

				header('Content-Type: application/json');
				echo json_encode($reply);
			    wp_die();
			}*/

			$requestUrl = "products/index?recursive=-1{$catalogsStr}&webpos=1&limit={$limit}&offset={$offset}&company={$company_id}";
			
			$data = array(
				'sid' => $this->sid,
			);

			$result = $this->request($requestUrl, $data);
			$productsData = json_decode($result['content']);
			
			$products = [];
			if(!empty($productsData->data)) {
				foreach($productsData->data as $product) {
					$args = array(
						'status' => 'publish',
						'limit'        => 1,
		    			'meta_key'     => '_bims_id',
		    			'meta_compare' => '=',
		    			'meta_value' => $product->Product->id
					);

					$wcProduct = wc_get_products( $args );
					if(!empty($wcProduct)) {
						$wcProduct = $wcProduct[0];
					}

					$bproduct = [
						!empty($wcProduct) ? $wcProduct->get_id() : '',
						!empty($wcProduct) ? $wcProduct->get_parent_id() : '',
						$product->Product->id,
						$product->Product->name,
						$product->Ptype->name,
						'BIMS',
					];

					$attrs = [];

					foreach($attributes as $key => $attribute) {
						$val = '';
						if(!empty($product->ProductsCustomField)) {
							foreach($product->ProductsCustomField as $customField) {
								if($customField->ProductsCustomField->name == $key)
									$val = $customField->ProductsCustomField->value;
							}
						}

						$bproduct[] = $val;
						$attrs[$attribute] = $val;
					}

					$products[] = $bproduct;
					$product->Product->attrs = $attrs;
					$product->Product->wc_id = !empty($wcProduct) ? $wcProduct->get_id() : '';
					$product->Product->wc_parent_id = !empty($wcProduct) ? $wcProduct->get_parent_id() : '';
					
					$wpdb->delete($tabla_products, ['product_id' => $product->Product->id]);
					$wpdb->insert($tabla_products, [
						'product_id' => $product->Product->id,
						'code' => $product->Product->code,
						'text' => json_encode($product)
					]);
				}
			}

			$reply = [
				'sid' => $this->sid,
				'offset' => $offset + $limit,
				'products' => $products,
				'count' => count($products)
			];

			header('Content-Type: application/json');
			echo json_encode($reply);
		    wp_die();
		}


		function remove_sku() {
			$args = [
				'status'    => 'publish',
				'orderby' => 'name',
				'order'   => 'ASC',
				'limit' => -1,
			];
			$all_products = wc_get_products($args);
			foreach ($all_products as $key => $product) {
				if ($product->get_type() == "variable") {
					if(!empty($product->get_variation_attributes())) {
						echo $product->get_title()." tiene variaciones <br />";
						$product->set_sku(null);
						$product->save();
						echo $product->get_title()." se le quita el SKU<br />";
					} else {
						echo $product->get_title()." no tiene variaciones <br />";
					}
				}
				
			}
			die();
		}
		
		function agregar_columna_fecha_modificacion( $columns ) {
		    $columns['fecha_modificacion'] = __( 'Fecha de modificación', 'woocommerce' );
		    return $columns;
		}


		function mostrar_valor_columna_fecha_modificacion( $column, $post_id ) {
		    if ( $column == 'fecha_modificacion' ) {
		        $post_modified = get_post_modified_time( 'G', true, $post_id );
		        echo esc_html( date_i18n( get_option( 'date_format' ), $post_modified ) );
		    }
		}

		public function init_bimsc_admin() {
			// WC()->session = new WC_Session_Handler;
			// WC()->customer = new WC_Customer;
			// $this->checkout_fields = WC()->checkout->get_checkout_fields();
		}

		public function bims_order_completed( $order_id ){
		   $order = new WC_Order( $order_id );
		   $this->so_payment_complete($order_id);
		}
	
		public function bimsc_thankyou( $order_id ) {
		    $order = wc_get_order( $order_id );
		    /*if ( 'cod' === $order->get_payment_method() ) {
		    	if( $order->get_status()<>'completed' )
		        	$order->update_status( 'completed' );
		    }*/
			if (!wp_next_scheduled('action_payment_complete', array($order_id))) {
			    wp_schedule_single_event(time() + 10, 'action_payment_complete', array($order_id));
			}

		}

		public function bimsc_finish_order( $order_id, $status_from, $status_to ) { 
			$allowed_statuses = [ 'on-hold', 'processing' ]; //completed
			$order = wc_get_order( $order_id );
			$order->add_order_note( 'BIMS Order Status: '.$status_to );

			if(in_array($status_to, $allowed_statuses)) {
				if (!wp_next_scheduled('action_payment_complete', array($order_id))) {
				    wp_schedule_single_event(time() + 10, 'action_payment_complete', array($order_id));
				}
			}
		}

		private function getLastSync() {
			return get_option('bims_last_sync');

		}

		public function syncBIMS() {
			global $wp_roles, $wpdb;
			$all_roles = $wp_roles->roles;

			if(true || $_GET['pw']==md5(get_option('bimsc_password'))) {
				$this->login();
				$data = array();
				$data['posales'] = $this->request('posales/index',array('sid'=>$this->sid));
				$data['agencies'] = $this->request('agencies/index',array('sid'=>$this->sid));
				$data['companies'] = $this->request('companies/index',array('sid'=>$this->sid));
				$data['currencies'] = $this->request('currencies/index',array('sid'=>$this->sid));
				$data['warehouses'] = $this->request('warehouses/index',array('sid'=>$this->sid));
				$data['pricings'] = $this->request('pricings/index',array('sid'=>$this->sid));
				$data['payment_methods'] = $this->request('payment_methods/index',array('sid'=>$this->sid));
				$data['catalogs'] = $this->request('catalogs/index',array('sid'=>$this->sid));

				$data['posales'] = json_decode($data['posales']['content'], true)['data'];
				$data['agencies'] = json_decode($data['agencies']['content'], true)['data'];
				$data['companies'] = json_decode($data['companies']['content'], true)['data'];
				$data['currencies'] = json_decode($data['currencies']['content'], true)['data'];
				$data['warehouses'] = json_decode($data['warehouses']['content'], true)['data'];
				$data['pricings'] = json_decode($data['pricings']['content'], true)['data'];
				$data['payment_methods'] = json_decode($data['payment_methods']['content'], true)['data'];
				$data['catalogs'] = json_decode($data['catalogs']['content'], true)['data'];

				foreach($data['posales'] as $posale) {
					$result = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}bimsc_posales WHERE posale_id = {$posale['Posale']['id']}");
					$company = array();
					foreach($data['companies'] as $company_record) {
						if($company_record['Company']['id'] == $posale['Agency']['company_id']) {
							$company = $company_record;
						}
					}
					if(!empty($result)) {
						$wpdb->update($wpdb->prefix.'bimsc_posales', [
							'company_id' => $posale['Agency']['company_id'],
							'agency_id' => $posale['Agency']['id'],
							'posale_id' => $posale['Posale']['id'],
							'company_name' => $company['Company']['name'],
							'agency_name' => $posale['Agency']['name'],
							'posale_name' => $posale['Posale']['name']
						], ['posale_id' => $posale['Posale']['id']]);
					} else {
						$wpdb->insert($wpdb->prefix.'bimsc_posales', [
							'company_id' => $posale['Agency']['company_id'],
							'agency_id' => $posale['Agency']['id'],
							'posale_id' => $posale['Posale']['id'],
							'company_name' => $company['Company']['name'],
							'agency_name' => $posale['Agency']['name'],
							'posale_name' => $posale['Posale']['name']
						]);
					}
				}


				header('Content-Type: application/json');
				echo json_encode([
		            "status" => "success",
		            'data' => $data,
		        	'last_updated' => current_time("Y-m-d H:i:s")
		        ]);
		        exit;
			} else {
				header('Content-Type: application/json');
				echo json_encode([
		            "status" => "denied",
		        	'last_updated' => current_time("Y-m-d H:i:s")
		        ]);
				exit;
			}
		}

		public function syncProducts() {
			$send_no_cache_headers = apply_filters('rest_send_nocache_headers', is_user_logged_in());
			if (!$send_no_cache_headers && !is_admin() && $_SERVER['REQUEST_METHOD'] == 'GET') {
			    $nonce = wp_create_nonce('wp_rest');
			    $_SERVER['HTTP_X_WP_NONCE'] = $nonce;
			}

			$cursor = get_option('bimsc_cursor');
			if(!$cursor || !empty($_GET['reset'])) {
				update_option('bimsc_cursor', 1);
				$cursor = 1;
			}

			$last_sync = get_option('bims_last_sync');

			$count = (new WP_Query(['post_type' => 'product', 'post_status' => 'publish']))->found_posts;

			$args = array(
				'status' => 'publish',
				'limit'        => 50,
				'page' => $cursor,
                'orderby'      => 'date_modified',
                'order'        => 'DESC',
    			'meta_key'     => '_bims_id',
    			'meta_compare' => 'EXISTS',
			);

			$products = wc_get_products( $args );
			if(empty($products)) {
				header('Content-Type: application/json');
				echo json_encode([
	                "status" => "finished",
	                'last_updated' => current_time("Y-m-d H:i:s")
	            ]);
	            update_option('bimsc_cursor', 0);
				exit;
			}

			$data = array();
			foreach($products as $product) {
				$category_ids = $product->get_category_ids();
				if(empty($category_ids)) {
					wp_set_object_terms( $product->get_id(), array('Uncategorized'), 'product_cat' );

					$terms = wp_get_object_terms( $product->get_id(), 'product_cat' );

					$category_ids = array(
						$terms[0]->term_id
					);
				}

				if(empty($product->get_sku())) {
					$product->set_sku("WBIMS-".$product->get_id());
					$product->save();
				}

				$category = get_term_by( 'id', $category_ids[0], 'product_cat' );
				
				$data[$product->get_id()] = array(
					'id' => $product->get_id(),
					'code' => $product->get_sku(),
					'name' => $product->get_name(),
					'description' => $product->get_description(),
					'price' => $product->get_price(),
					'qty' => $product->get_stock_quantity(),
					'bims_id' => !empty($product->get_meta('_bims_id')) ? $product->get_meta('_bims_id') : null,
					'last_updated' => $product->get_date_modified()->current_time("Y-m-d H:i:s"),
					'company_id' => get_option('bimsc_company_id'),
					'sell_price_currency_id' => get_option('bimsc_currency_id'),
					'buy_price_currency_id' => get_option('bimsc_currency_id'),
					'Category' => array(
						'id' => $category->term_id,
						'name' => $category->name
					)
				);

			}

			$this->sendToBims($data);

			$totalCursors = round($count / $args['limit']);

			// update_option('bims_last_sync', current_time("Y-m-d H:i:s"));
			header('Content-Type: application/json');
            echo json_encode([
                "status" => "success",
                'count' => $count,
                'progress' => (int)$totalCursors,
                'cursor' => (int)$cursor,
                'last_updated' => current_time("Y-m-d H:i:s")
            ]);

			$cursor++;
			update_option('bimsc_cursor', $cursor);
            exit;
		}

		private function debug($string) {
			echo '<pre>'.print_r($string, true).'</pre>';
		}

		private function sendToBims($products) {
			$this->login();
			$data = array(
				'sid' => $this->sid,
				'data' => array(
					'WCProduct' => $products
				)
			);
			$result = $this->request('products/wcsync', $data);
			if(!empty($result['content'])) {
				$bproducts = json_decode($result['content'], true);
				if(!empty($bproducts['data'])) {
					foreach($bproducts['data']['WCProduct'] as $wproduct) {
						$product_id = wc_get_product_id_by_sku( $wproduct['code'] );
						$product = wc_get_product( $product_id );
						$product->set_price($wproduct['price']);
						$product->set_regular_price($wproduct['price']);
						$product->set_sale_price($wproduct['price']);
						$product->set_stock_quantity($wproduct['availability']);
						if($wproduct['availability']>0) {
							$product->set_manage_stock(true);
							$product->set_stock_status('instock');
						}
						else {
							$product->set_manage_stock(false);
							$product->set_stock_status('outofstock');
						}
						update_post_meta( $product_id, '_bims_id', $wproduct['bims_id'] );
						$product->save();
					}
				}
			}
		}

		public function receiveFromBimsNew() {
		    // Obtener todos los productos publicados que tienen el meta '_bims_id'
		    $wcProducts = wc_get_products(array(
		        'status'       => 'publish',         // Solo productos publicados
		        'limit'        => -1,                // Sin límite de resultados
		    ));

		    $bimsIds = array(); // Array para almacenar los _bims_id

		    foreach ($wcProducts as $product) {
		        // Verificar si es un producto variable
		        if ($product->is_type('variable')) {
		            // Obtener todas las variaciones del producto variable
		            $variations = $product->get_children();

		            foreach ($variations as $variation_id) {
		                $variation = wc_get_product($variation_id);

		                // Verificar si la variación tiene el meta '_bims_id'
		                $variation_bims_id = $variation->get_meta('_bims_id');
		                if (!empty($variation_bims_id)) {
		                	WP_CLI::line($variation_bims_id);
		                    $bimsIds[] = $variation_bims_id;
		                }
		            }
		        } else {
		            // Producto simple
		            $bims_id = $product->get_meta('_bims_id');
		            if (!empty($bims_id)) {
		            	WP_CLI::line($bims_id);
		                $bimsIds[] = $bims_id;
		            }
		        }
		    }

		    // Dividir el array de _bims_id en chunks de 100
		    $bimsIdsChunks = array_chunk($bimsIds, 100);
		    $this->login();
		    foreach ($bimsIdsChunks as $chunk) {		        
		        $result = $this->sendBimsIdsToERP($chunk);
		        print_r($result);
		    }
		}

		private function sendBimsIdsToERP($ids) {
			$data = array(
				'sid' => $this->sid,
			);

			$company_id = get_option('bimsc_company_id');

			$catalogsData = get_option('bimsc_catalogs');
			// $catalogsData = "";
			$catalogs = array();
			$catalogsStr = "&";
			if(!empty($catalogsData)) {
				$catalogs = explode(",", $catalogsData);
				foreach($catalogs as $i => $catalog) {
					$catalogsStr .= "catalog[{$i}]={$catalog}&";
				}
			}

			$idsToSend = array();
			$idsToSendStr = "&";
			if(!empty($ids)) {
				foreach($ids as $i => $id) {
					$idsToSendStr .= "id[{$i}]={$id}&";
				}
			}

			$requestUrl = "products/index?recursive=-1{$idsToSendStr}{$catalogsStr}full_images=1&v_stock=1&webpos=1&company={$company_id}";
			WP_CLI::line($requestUrl);
			$result = $this->request($requestUrl, $data);

			if(!empty($result['content'])) {
				$products = json_decode($result['content'], true);
				if(!empty($products['data'])) {
					$doneProducts = $this->updateProducts($products['data']);
					return [
		                "status" => "success",
		                'downloaded' => $products,
		                "data" => $doneProducts,
		                'last_updated' => current_time("Y-m-d H:i:s"),
						'url' => $requestUrl
		            ];
				} else {
					return [
		                "status" => "done",
		                "message" => "Finalizado",
		                'last_updated' => current_time("Y-m-d H:i:s")
		            ];
				}
			} else {
				return [
					'status' => 'not-found',
					'url' => $requestUrl
				];
			}
		}

		public function receiveFromBims() {
			$this->login();
			$data = array(
				'sid' => $this->sid,
			);

			if(!empty($_GET['reset'])) {
				update_option('bimsc_offset_fb', 0);
				update_option('bimsc_last_sync_fb', null);
			}
			
			$limit = 200;
			$offset = !empty(get_option('bimsc_offset_fb')) ? get_option('bimsc_offset_fb') : 0;

			if($offset===0) {
				update_option('bimsc_offset_fb', 0);
			}

			$last_update = !empty(get_option('bimsc_last_sync_fb')) ? get_option('bimsc_last_sync_fb') : null;
			$last_update = urlencode($last_update);
			$last_update = null;
			
			$company_id = get_option('bimsc_company_id');

			$catalogsData = get_option('bimsc_catalogs');
			// $catalogsData = "";
			$catalogs = array();
			$catalogsStr = "&";
			if(!empty($catalogsData)) {
				$catalogs = explode(",", $catalogsData);
				foreach($catalogs as $i => $catalog) {
					$catalogsStr .= "catalog[{$i}]={$catalog}&";
				}
			}

			// $ids = [22775,22776,22777,22778];
			// $idParams = http_build_query(['id' => $ids], '', '&');
			// $requestUrl = "products/index?recursive=-1{$catalogsStr}full_images=1&v_stock=1&webpos=1&limit={$limit}&company={$company_id}&{$idParams}";
			
			$requestUrl = "products/index?debug=1&recursive=-1{$catalogsStr}full_images=1&v_stock=1&webpos=1&limit={$limit}&offset={$offset}&last_update={$last_update}&company={$company_id}";
			$result = $this->request($requestUrl, $data);

			if(!empty($result['content'])) {
				$products = json_decode($result['content'], true);
				if(!empty($products['count'])) {
					update_option('bimsc_count_fb', $products['count']);
					$count = $products['count'];
				} else {
					$count = $products['count'] = get_option('bimsc_count_fb');
				}
				if($offset < $products['count']) {
					if(!empty($products['data'])) {
						$doneProducts = $this->updateProducts($products['data']);
						header('Content-Type: application/json');
						echo json_encode([
			                "status" => "success",
			                "cursor" => $offset + $limit,
			                'downloaded' => $products,
			                "data" => $doneProducts,
			                "count" => $count,
			                'last_updated' => current_time("Y-m-d H:i:s"),
							'url' => $requestUrl
			            ]);
						$offset += $limit;
						update_option('bimsc_offset_fb', $offset);
						exit;
					} else {
						update_option('bimsc_offset_fb', 0);
						update_option('bimsc_last_sync_fb', null);
						header('Content-Type: application/json');
						echo json_encode([
			                "status" => "done",
			                "message" => "Finalizado",
			                'last_updated' => current_time("Y-m-d H:i:s")
			            ]);
			            exit;
					}
				} else {
					update_option('bimsc_offset_fb', 0);
					update_option('bimsc_last_sync_fb', null);
					header('Content-Type: application/json');
					echo json_encode([
		                "status" => "done",
		                "message" => "Finalizado",
		                'last_updated' => current_time("Y-m-d H:i:s")
		            ]);
		            exit;
				}
			}

			header('Content-Type: application/json');
            echo json_encode([
                "status" => "error",
                "message" => "No hay datos a descargar",
                'last_updated' => current_time("Y-m-d H:i:s")
            ]);
            exit;
		}

		public function notifyToBims() {
			$this->login();
			$data = array(
				'sid' => $this->sid,
			);

			$result = $this->request('products/notinwoocommerce', $data);
			
			header('Content-Type: application/json');
            echo json_encode([
                "status" => "success",
                "result" => json_decode($result['content'], true),
                "message" => "Notificación enviada",
                'last_updated' => current_time("Y-m-d H:i:s")
            ]);
            exit;
		}

        public function sync() {
            $this->login();
            $orders = wc_get_orders( array(
                'limit'        => -1, // Query all orders
                'orderby'      => 'date',
                'order'        => 'DESC',
                'meta_key'     => 'bims_id', // The postmeta key field
                'meta_compare' => 'NOT EXISTS', // The comparison argument
            ));
            header('Content-type: application/json');
            $ids = array();
            foreach($orders as $order) {
                if($order->get_status()=='completed') {
                    $order_id = $order->get_id();
                    $bims_id = get_post_meta( $order_id, 'bims_id', true );
                    if(empty($bims_id)) {
                        $ids[] = array(
                            "bims_id" => $this->so_payment_complete($order_id),
                            "order_id" => $order_id
                        );
                    }
                }
            }
            header('Content-Type: application/json');
            echo json_encode([
                "status" => "success",
                'orders' => $ids
            ]);
            exit;
        }

		public function receiveJson() {
		    $sale = file_get_contents('php://input');
		    $sale = json_decode($sale, true);
		    $status = $_GET['status'];
		    $sale_id = $sale['Sale']['id'];

		    $orders = wc_get_orders(array(
		        'meta_key' => 'bims_id',
		        'meta_value' => $sale_id,
		    ));

		    if (!empty($orders)) {
		        $wc_order = reset($orders);
		        if($_GET['status'] == 'paid') {
		        	$wc_order->update_status('completed');
		        } else if($_GET['status'] == 'void') {
		        	$wc_order->update_status('cancelled');
		        }

				echo json_encode([
		            "status" => "success",
		            'data' => 'Actualizado',
		        	'last_updated' => current_time("Y-m-d H:i:s")
		        ]);
		        exit;
		    } else {
				echo json_encode([
		            "status" => "error",
		            'data' => 'No se ha encontrado pedido',
		        	'last_updated' => current_time("Y-m-d H:i:s")
		        ]);
		        exit;
		    }
		}
		
		private function createContact($data) {
			$this->login();
			$data = array(
				'sid' => $this->sid,
				'data' => array(
					'Contact' => $data
				)
			);
			
			$result = $this->request('contacts/edit', $data);
			$result = json_decode($result['content']);
			// echo '<pre>'.print_r($result, true).'<pre>';
			$contactId = !empty($result->data->Contact->id) ? $result->data->Contact->id : false;

			return $contactId;			
		}

		private function getContactId($documentId) {
			$data = array(
				'sid' => $this->sid
			);

			$documentType = strpos($documentId,"-") !== false ? 'ruc' : 'ci';

			/*if(strpos($documentId,"-") !== false) {
				$documentId = explode("-", $documentId);
				$documentId = $documentId[0];
			}*/
			
			$documentType = 'nit';

			$result = $this->request('contacts/index?document_type='.$documentType.'&document_id='.$documentId, $data);
			$result = json_decode($result['content']);
			// echo '<pre>'.print_r($result, true).'<pre>';
			$contactId = !empty($result->data[0]->Contact->id) ? $result->data[0]->Contact->id : false;

			return $contactId;
		}

		private function send_order($data) {
			$this->login();
			$data = array(
				'sid' => $this->sid,
				'data' => $data
			);

			$result = $this->request('sales/edit?notify=1', $data);
			return $result['content'];
		}

		private function login() {
			if (empty($this->user) || empty($this->password)) {
				error_log('BIMSC Login Error: Credenciales vacías');
				return false;
			}

			$data = array(
				'user' => $this->user,
				'password' => md5($this->password),
			);

			if(!empty($this->tenant)) {
				$data['tenant'] = $this->tenant;
			}

			$response = $this->request('users/login', $data);
			if(!empty($response['content'])) {
				$response['content'] = json_decode($response['content'], true);
				if(!empty($response['content']['data']['Session'])) {
					$this->sid = $response['content']['data']['Session']['id'];
					return true;
				} else {
					error_log('BIMSC Login Error: ' . json_encode($response['content']));
					return false;
				}
			}
			
			error_log('BIMSC Login Error: Respuesta vacía');
			return false;
		}

		public function getProducts() {
			$data = array(
				'sid' => $this->sid,
			);
			
			$response = $this->request('products/index', $data);
			$response = json_decode($response['content'], true);
			return $response;
		}

		public function getPtypes() {
			$data = array(
				'sid' => $this->sid,
			);
			
			$response = $this->request('ptypes/index', $data);
			$response = json_decode($response['content'], true);
			return $response;
		}

		public function getLabels() {
			$data = array(
				'sid' => $this->sid,
			);
			
			$response = $this->request('labels/index', $data);
			$response = json_decode($response['content'], true);
			return $response;
		}

		public function request($url, $data = null) {
			// $this->debug($data);
			// echo $this->url.'/api/'.$url;
			$finalUrl = $this->url.'/api/'.$url;
			$sso_ch = curl_init( $finalUrl );
			// file_put_contents("/var/web/tienda-demo.conecciones.net/log.log", $finalUrl.PHP_EOL, FILE_APPEND);
			// file_put_contents("/var/web/tienda-demo.conecciones.net/log.log", json_encode($data).PHP_EOL, FILE_APPEND);
			curl_setopt( $sso_ch, CURLOPT_HEADER, false );
			curl_setopt( $sso_ch, CURLOPT_SSL_VERIFYPEER, false );
			curl_setopt( $sso_ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $sso_ch, CURLOPT_FOLLOWLOCATION, true );
	        curl_setopt( $sso_ch, CURLOPT_POST, true);
	        curl_setopt( $sso_ch, CURLOPT_POSTFIELDS, http_build_query($data));
			$c = curl_exec( $sso_ch );
		    $err     = curl_errno( $sso_ch );
		    $errmsg  = curl_error( $sso_ch );
		    $header  = curl_getinfo( $sso_ch );
		    curl_close( $sso_ch );
		    $html['errno']   = $err;
		    $html['errmsg']  = $errmsg;
		    $html['content'] = $c;
		    if(!empty($_GET['debug'])) {
		    	var_dump($data);
		    	var_dump($html);
		    }
		    return $html;
		}

		public function so_payment_complete( $order_id, $posale_id = null ){
			global $wpdb;
			$order = wc_get_order( $order_id );
			
			if(empty(get_option('bimsc_docid_field'))) {
				$order->add_order_note("No Doc Id");
				return false;
			}

			$bims_id = $order->get_meta('bims_id');

			$order->add_order_note('_'.get_option('bimsc_docid_field'));
			$order->add_order_note($order->get_meta('_'.get_option('bimsc_docid_field')));

			if(!empty($bims_id)) {
				$order->add_order_note("El pedido ya fue enviado a BIMS");
				return false;
			}
			
			if( sizeof( $order->get_refunds() ) > 0 ) {
				$order->add_order_note("Refunds");
				return false;
			}

			$items = $order->get_items();
			$documentId = $order->get_meta('_'.get_option('bimsc_docid_field'));
			$billingCompanyName = get_post_meta( $order_id, '_billing_company', true );

			$shipping_address_1 = $order->get_billing_address_1();
			$shipping_address_2 = $order->get_billing_address_2();
			$shipping_name_1 = $order->get_billing_first_name();
			$shipping_name_2 = $order->get_billing_last_name();
			$shipping_phone = $order->get_billing_phone();
			$shipping_city = $order->get_billing_city();
			$contact_address = $shipping_address_1." ".$shipping_address_2." Ciudad: ".$shipping_city;
			$contactId = $this->getContactId($documentId);

			// ============================================================
			// DETECCIÓN DE PUNTO DE VENTA Y MÉTODOS DE PAGO
			// ============================================================
			
			// Detectar punto de venta desde FooEvents POS
			$user_id_meta = null;
			foreach($order->get_meta_data() as $meta) {
				if($meta->key === '_fooeventspos_user_id') {
					$user_id_meta = $meta->value;
					break;
				}
			}

			// Verificar descuentos del 100% (cortesías)
			$total = floatval($order->get_total());
			$discount = floatval($order->get_discount_total());

			if($total == 0 && $discount > 0) {
				$order->add_order_note("No procesado: Descuento del 100%");
				return false;
			}

			if(empty($user_id_meta)) {
				// ========== COMPRA WEB ==========
				$posale_id = 6; // Caja WEB
				$sales_payment_methods = [
					[
						'payment_method_id' => 28, // En línea
						'amount' => $order->get_total(),
						'vaucher' => $order->get_transaction_id()
					]
				];
				
				// Obtener campos personalizados de facturación WEB
				$ruc = $order->get_meta('_billing_ruc');
				$gov_id = $order->get_meta('_billing_documento');
				$social_reason = $order->get_meta('_billing_razon_social');
				
			} else {
				// ========== COMPRA DESDE POS ==========
				$user_id_value = intval($user_id_meta);
				
				// Verificar si es administrador
				if($user_id_value == 2) {
					$order->add_order_note("No procesado: orden desde cuenta administrador");
					return false;
				}
				
				// Determinar punto de venta según usuario
				if($user_id_value == 729) {
					$posale_id = 4; // SAN COSMOS
				} elseif($user_id_value == 3) {
					$posale_id = 1; // TATAKUALAB
				} else {
					$posale_id = 7; // Otro punto de venta
				}
				
				// Verificar si es cortesía
				if($order->get_payment_method_title() === 'Cortesía') {
					$order->add_order_note("No procesado: Cortesía");
					return false;
				}
				
				// Extraer métodos de pago de FooEvents POS
				$payments_meta = null;
				foreach($order->get_meta_data() as $meta) {
					if($meta->key === '_fooeventspos_payments') {
						$payments_meta = $meta->value;
						break;
					}
				}
				
				if(!empty($payments_meta)) {
					$payments = json_decode($payments_meta, true);
					
					// Mapeo de métodos de pago FooEvents POS -> BIMS
					$method_mapping = [
						'fooeventspos_check_payment' => 34,  // Gift Card
						'fooeventspos_cash' => 21,           // Efectivo
						'fooeventspos_cash_on_delivery' => 26, // Transferencia
						'fooeventspos_other' => 27,          // Bancard
						'fooeventspos_online' => 28          // En línea
					];
					
					$sales_payment_methods = [];
					
					if(count($payments) === 1 && !isset($payments[0]['amount'])) {
						// Un solo método de pago, pagó el total
						$method_id = $method_mapping[$payments[0]['opmk']] ?? 28;
						$sales_payment_methods[] = [
							'payment_method_id' => $method_id,
							'amount' => $order->get_total(),
							'vaucher' => $order->get_transaction_id()
						];
					} else {
						// Múltiples métodos de pago
						foreach($payments as $payment) {
							$method_id = $method_mapping[$payment['opmk']] ?? 28;
							$sales_payment_methods[] = [
								'payment_method_id' => $method_id,
								'amount' => floatval($payment['amount']),
								'vaucher' => $payment['reference'] ?? ''
							];
						}
					}
				} else {
					// Fallback: usar método de pago estándar de WooCommerce
					$payment_method = $order->get_payment_method();
					$pms = unserialize(get_option('bimsc_pms'));
					$bims_pm_id = $pms[$payment_method] ?? 28;
					
					$sales_payment_methods = [
						[
							'payment_method_id' => $bims_pm_id,
							'amount' => $order->get_total(),
							'vaucher' => $order->get_transaction_id()
						]
					];
				}
				
				// Obtener campos de facturación desde POS (shipping)
				$ruc = $order->get_shipping_company();
				$social_reason = $order->get_shipping_last_name();
				$gov_id = null;
			}

			// Determinar tipo de documento y nombre
			$document_type = 'ci';
			$document_id = '';

			if(!empty($ruc) && $ruc !== '') {
				$document_type = 'ruc';
				$document_id = $ruc;
			} elseif(!empty($gov_id) && $gov_id !== '') {
				$document_type = 'ci';
				$document_id = $gov_id;
			} else {
				// Fallback: usar el documento original si existe
				if(!empty($documentId)) {
					$document_id = $documentId;
					$document_type = strpos($documentId, "-") !== false ? 'ruc' : 'ci';
				}
			}

			// Nombre del contacto
			if(!empty($social_reason) && $social_reason !== '') {
				$contact_name = $social_reason;
			} elseif(!empty($billingCompanyName)) {
				$contact_name = $billingCompanyName;
			} else {
				$contact_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
			}

			// Obtener información del punto de venta
			if(!empty($posale_id)) {
				$posale = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}bimsc_posales WHERE posale_id = {$posale_id}");
			}

			// Obtener el primer método de pago para compatibilidad con código existente
			$bims_pm_id = $sales_payment_methods[0]['payment_method_id'];

			// ============================================================
			// CONSTRUIR DATOS DE LA VENTA
			// ============================================================

			$data = array(
				'Sale' => array(
					"company_id" => !empty($posale) ? $posale->company_id : get_option('bimsc_company_id'),
					"agency_id" => !empty($posale) ? $posale->agency_id: get_option('bimsc_agency_id'),
					"posale_id" => $posale_id,
					"billed" => true,
					"currency_id" => get_option('bimsc_currency_id'),
					"preorder" => false,
					'preorder_status' => 'confirmed',
					'status' => 'pending',
					"issue_date" => date("Y-m-d"),
					"production_datetime" => current_time("Y-m-d H:i:s"),
					'notes' => "Pedido de la Web #{$order_id}",
					'lock_preorder_payment_method' => true,
					'preorder_payment_method_id' => $bims_pm_id,
					"contact_address" => $contact_address,
					"contact_phones" => $shipping_phone,
					"delivery_authorized_person" => $shipping_name_1." ".$shipping_name_2,
					"channel" => "ecommerce",
					'edited_prices' => true
				),
				'SalesPaymentMethod' => $sales_payment_methods  // ✅ USAR VARIABLE CORRECTA
			);

			// Crear o usar contacto existente
			if(!empty($contactId)) {
				$data['Sale']['contact_id'] = $contactId;
			} else {
				$data['Contact'] = array(
					'name' => $contact_name,  // ✅ USAR VARIABLE NUEVA
					'address' => $order->get_billing_address_1()." ".$order->get_billing_address_2(),
					'city' => $order->get_billing_city(),
					'neighborhood' => $order->get_billing_state(),
					'zip_code' => $order->get_billing_postcode(),
					'country' => $order->get_billing_country(),
					'emails' => $order->get_billing_email(),
					'phones' => $order->get_billing_phone(),
					'document_id' => $document_id,  // ✅ USAR VARIABLE NUEVA
					'document_type' => $document_type,  // ✅ USAR VARIABLE NUEVA
				);
				$contactId = $this->createContact($data['Contact']);
				$order->add_order_note(json_encode($data['Contact']));
				if(!empty($contactId)) {
					unset($data['Contact']);
					$data['Sale']['contact_id'] = $contactId;
				}
			}

			// ============================================================
			// PROCESAR PRODUCTOS (UNA SOLA VEZ)
			// ============================================================

			// Detectar si hay descuentos globales
			$total_bruto = $order->get_subtotal();
			$descuento_global = 0;
			$porcentaje_descuento = 0;

			foreach ($order->get_fees() as $fee) {
				if (stripos($fee->get_name(), 'Descuento por forma de pago') !== false) {
					$descuento_global += abs($fee->get_total());
					if (preg_match('/\(([\d.]+)%\)/', $fee->get_name(), $matches)) {
						$porcentaje_descuento = floatval($matches[1]);
					}
				}
			}

			// Procesar cada producto
			foreach($items as $item) {
				$product = $item->get_product();
				if(empty($product->get_meta('_bims_id')))
					continue;
				
				$cantidad = $item->get_quantity();
				$product_id = $product->get_id();
				$total_item = floatval($item->get_total());
				$total_tax = floatval($item->get_total_tax());
				
				// IDs de productos especiales
				$productos_especiales = [19657, 14372, 8421, 3681, 24482, 10648, 14369];
				
				if(in_array($product_id, $productos_especiales)) {
					// Productos especiales: cantidad 1, precio = total
					$data['SalesProduct'][] = [
						'product_id' => $product->get_meta('_bims_id'),
						'quantity' => 1.00,
						'price' => $total_item,
						'notaxes' => false,
						'edited_prices' => true
					];
				} else {
					// Productos normales
					if($descuento_global > 0) {
						// CON descuento
						$precio_unitario_bruto = $item->get_subtotal() / $cantidad;
						$proporcion = $item->get_subtotal() / $total_bruto;
						$descuento_item_total = $proporcion * $descuento_global;
						$descuento_unitario = $descuento_item_total / $cantidad;
						
						$nota = $porcentaje_descuento > 0 ? "Incluye descuento del {$porcentaje_descuento}% por forma de pago seleccionada" : '';
						
						$data['SalesProduct'][] = [
							'product_id' => $product->get_meta('_bims_id'),
							'quantity' => $cantidad,
							'price' => round($precio_unitario_bruto, 2),
							'discount_amount' => round($descuento_unitario, 2),
							'notaxes' => false,
							'edited_prices' => true,
							'notes' => $nota
						];
					} else {
						// SIN descuento
						$precio_unitario = ($total_item + $total_tax) / $cantidad;
						
						$data['SalesProduct'][] = [
							'product_id' => $product->get_meta('_bims_id'),
							'quantity' => $cantidad,
							'price' => round($precio_unitario, 2),
							'notaxes' => false,
							'edited_prices' => true
						];
					}
				}
			}

			// Agregar Tips si existen
			foreach($order->get_fees() as $fee) {
				if($fee->get_name() === 'Tip') {
					$data['SalesProduct'][] = [
						'product_id' => 100, // ID del producto "Propina" en BIMS
						'quantity' => 1.00,
						'price' => floatval($fee->get_total()),
						'notaxes' => false
					];
				}
			}

			// Agregar envío si existe
			if($order->get_total_shipping() > 0) {
				$data['SalesProduct'][] = [
					'product_id' => get_option('bimsc_shipping_product_id'),
					'quantity' => 1,
					'price' => $order->get_total_shipping(),
				];
			}

			// Verificar que haya productos
			if(empty($data['SalesProduct'])) {
				$order->add_order_note("No hay productos con _bims_id para procesar");
				return false;
			}

			// ============================================================
			// ENVIAR A BIMS
			// ============================================================

			$result = $this->send_order($data);
			$result = json_decode($result, true);
			
			if(!empty($result['data']['Sale']['id'])) {
				$order->update_meta_data('bims_id', $result['data']['Sale']['id']);
				$order->update_meta_data('bims_payment_method_id', $bims_pm_id);
				$order->update_meta_data('bims_json', json_encode($data));
				$order->update_meta_data('bims_result', json_encode($result));
				$order->save();
				$order->add_order_note("{$this->url}/sales/view/{$result['data']['Sale']['id']}");
				return $result['data']['Sale']['id'];
			} else {
				$order->update_meta_data('bims_json', json_encode($data));
				$order->update_meta_data('bims_result', json_encode($result));
				$order->update_meta_data('bimsc_failed', '1'); 
				$order->save();
				$order->add_order_note("Error al enviar a BIMS - revisar meta bims_result");
				return false;
			}
		}

		private function createProduct($product) {
			if(!empty($product['Product']['code2']))
				$product['Product']['code'] = empty($product['Product']['code']) ? $product['Product']['code2'] : $product['Product']['code'];

			if(empty($product['Product']['code']))
				return;

			$product['Product']['sell_price'] *= $product['SellPriceCurrency']['buy_price'];

			$warehousesData = get_option('bimsc_warehouses');
			$warehouses = array();
			if(!empty($warehousesData)) {
				$warehouses = explode(",", $warehousesData);
			}

			$stockTotal = 0;

			if(!empty($product['Product']['AvailabilityFull'])) {
				foreach($product['Product']['AvailabilityFull'] as $stock) {
					if(!empty($warehouses) && !in_array($stock['warehouse_id'], $warehouses))
						continue;
					
					$stockTotal += floatval($stock['total']);
				}
			}

			$dproduct = new WC_Product_Simple();

			$dproduct->set_name($product['Product']['name']);
			$dproduct->set_sku($product['Product']['code']);
			$dproduct->set_price($product['Product']['sell_price']);
			$dproduct->set_regular_price($product['Product']['sell_price']);
			$dproduct->set_sale_price($product['Product']['sell_price']);
			$dproduct->set_stock_quantity($stockTotal);
			if($stockTotal>0) {
				$dproduct->set_manage_stock(true);
				$dproduct->set_stock_status('instock');
			}
			else {
				$dproduct->set_manage_stock(false);
				$dproduct->set_stock_status('outofstock');
			}
			$dproduct->update_meta_data('_bims_id', $product['Product']['id']);
			$dproduct->update_meta_data('_bims_sync', current_time("Y-m-d H:i:s"));
			$dproduct->set_date_modified( time() );
			$dproduct->save();
			// $image = 'https://bims.app/img/upload/ss1_'.$product['Product']['image'];
			$product['Product']['image'] = str_replace("tims2_", "ss1_", $product['Product']['image']);
			$image_url = wc_rest_upload_image_from_url($product['Product']['image']);

			if(
				!empty($image_url->error)
				||	
				!empty($image_url->errors)
				||
				is_a($image_url, 'WC_Error')
				||
				$image_url instanceof WC_Error
			) {
				$image_url = null;
			}

			if(!empty($image_url)) {
				$image_id = wc_rest_set_uploaded_image_as_attachment($image_url, $dproduct->get_id());
				$dproduct->set_image_id($image_id);
			}

			if(!empty($product['ProductsImage'])) {
				$images = [];
				foreach($product['ProductsImage'] as $imz) {
					$imz = str_replace("tims2_", "ss1_", $imz);
					$image_url = wc_rest_upload_image_from_url($imz);
					if(!empty($image_url->error))
						continue;
					if(!empty($image_url->errors))
						continue;
					if (is_a($image_url, 'WC_Error'))
						continue;
					if ($image_url instanceof WC_Error)
						continue;
					$image_id = wc_rest_set_uploaded_image_as_attachment($image_url, $dproduct->get_id());
					$images[] = $image_id;
				}

				if(!empty($images)) {
					$dproduct->set_gallery_image_ids($images);
				}
			}

		    $category_data = array(
		        'name' => $product['Ptype']['name'],
		        'slug' => sanitize_title($product['Ptype']['name']),
		        'parent' => 0
		    );

		    $category_exists = term_exists($category_data['slug'], 'product_cat');

			if ($category_exists === 0 || $category_exists === null) {
		        $new_category = wp_insert_term($category_data['name'], 'product_cat', $category_data);
		        if (!is_wp_error($new_category)) {
		            $category_id = $new_category['term_id'];
		            wp_set_object_terms($dproduct->get_id(), $category_id, 'product_cat');
		        }
			} else {
				$category = get_term_by('slug', $category_data['slug'], 'product_cat');
				wp_set_object_terms($dproduct->get_id(), $category->term_id, 'product_cat');
			}
			
			$dproduct->save();

			return array(
				'WooCommerce' => array(
					'id' => $product_id,
					'sku' => $dproduct->get_sku(),
					'name' => $dproduct->get_name(),
					'price' => $dproduct->get_price(),
					'regular_price' => $dproduct->get_regular_price(),
					'stock' => $dproduct->get_stock_quantity(),
					'date' => current_time("Y-m-d H:i:s")
				),
				'Bims' => array(
					'id' => $product['Product']['id'],
					'code' => $product['Product']['code'],
					'name' => $product['Product']['name'],
					'price' => $product['Product']['sell_price'],
					'regular_price' => $product['Product']['sell_price'],
					'stock' => $product['Product']['availability'],
					'date' => current_time("Y-m-d H:i:s")
				)
			);
		}

		public function getPricing() {
			global $wpdb;
			$user = wp_get_current_user();
			$role = $user->roles[0];

			if(!$user->ID)
				return null;

			$pricings = unserialize(get_option('bimsc_roles'));

			$pricing_id = $pricings[$role];
			if(empty($pricing_id))
				return null;

			return $pricing_id;
		}
		
		private function updateProducts($products) {	
			global $wpdb;

			if(empty($products))
				return array();

			$returnData = array();

			foreach($products as $product) {
				if(!empty($product['Product']['code2']))
					$product['Product']['code'] = empty($product['Product']['code']) ? $product['Product']['code2'] : $product['Product']['code'];

				$args = array(
				    'post_type' => array('product', 'product_variation'),
				    'meta_query' => array(
				        array(
				            'key' => '_bims_id',
				            'value' => $product['Product']['id'],
				            'compare' => '='
				        )
				    )
				);

				// print_r($args);
				$query = new WP_Query($args);
				if($query->have_posts()) {
			        $query->the_post();
			        $product_id = get_the_ID();
			        // $wcProduct = wc_get_product($product_id);
					// $product_id = wc_get_product_id_by_sku( $product['Product']['code'] );
				} else {
					$returnData['NotInWooCommerce'][] = array(
						'bims_id' => $product['Product']['id'],
						'sku' => $product['Product']['code'],
						'name' => $product['Product']['name']
					);
					// $returnData[] = $this->createProduct($product);
					continue;
				}

				$product['SellPriceCurrency']['buy_price'] = !empty($product['SellPriceCurrency']['buy_price']) ? $product['SellPriceCurrency']['buy_price'] : 1;
				$product['Product']['sell_price'] *= $product['SellPriceCurrency']['buy_price'];

				$warehousesData = get_option('bimsc_warehouses');
				$warehouses = array();
				if(!empty($warehousesData)) {
					$warehouses = explode(",", $warehousesData);
				}
				
				$price = $product['Product']['sell_price'];
				if(!empty($product['ProductsCustomField'])) {
					foreach($product['ProductsCustomField'] as $customField) {
						if($customField['ProductsCustomField']['name'] == 'PP') {
							// var_dump("TIENE PP");
							// var_dump($customField['ProductsCustomField']['value']); die();
							$price = $customField['ProductsCustomField']['value'];
						}
					}
				}

				$dproduct = wc_get_product( $product_id );

				$dproduct->set_price($price);
				$dproduct->set_regular_price($price);
				$dproduct->set_sale_price($product['Product']['sell_price']);

				$dproduct->update_meta_data('_bims_id', $product['Product']['id']);
				$dproduct->update_meta_data('_bims_sync', current_time("Y-m-d H:i:s"));
				$dproduct->set_date_modified( time() );
				/*if(!empty($product['Product']['notes'])) {
					$dproduct->set_short_description(nl2br($product['Product']['notes']));
					$dproduct->set_description(nl2br($product['Product']['notes']));
				}*/

			    /*$category_data = array(
			        'name' => $product['Ptype']['name'],
			        'slug' => sanitize_title($product['Ptype']['name']),
			        'parent' => 0
			    );

			    $category_exists = term_exists($category_data['slug'], 'product_cat');

    			if ($category_exists === 0 || $category_exists === null) {
			        $new_category = wp_insert_term($category_data['name'], 'product_cat', $category_data);
			        if (!is_wp_error($new_category)) {
			            $category_id = $new_category['term_id'];
			            wp_set_object_terms($dproduct->get_id(), $category_id, 'product_cat');
			        }
    			} else {
    				$category = get_term_by('slug', $category_data['slug'], 'product_cat');
    				wp_set_object_terms($dproduct->get_id(), $category->term_id, 'product_cat');
    			}*/

				/*if(!empty($dproduct->get_parent_id())) {
					$pproduct = wc_get_product(  $dproduct->get_parent_id() );
					$pproduct->update_meta_data('_bims_sync', current_time("Y-m-d H:i:s"));
					$pproduct->set_date_modified( time() );
					$pproduct->save();
				}*/

				$stockTotal = 0;
				// $this->debug($product); die();
				if(!empty($product['Product']['AvailabilityFull'])) {
					foreach($product['Product']['AvailabilityFull'] as $stock) {
						if(!in_array($stock['warehouse_id'], $warehouses))
							continue;
						
						$stockTotal += floatval($stock['total']);

						$wResult = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}bimsc_stocks WHERE product_id = {$product_id} AND warehouse_id = {$stock['warehouse_id']}");
						
						if(count($wResult)>0) {
							$wpdb->update( 
							    $wpdb->prefix.'bimsc_stocks', 
							    array( 
							        'stock' => $stock['total'],
							    ), 
							    array( 'product_id' => $product_id, 'warehouse_id' => $stock['warehouse_id'] ),
							    array('%f'),
							    array('%d','%d')
							);
						} else {
							$wpdb->insert(
								$wpdb->prefix.'bimsc_stocks',
								array(
									'product_id' => $product_id,
									'warehouse_id' => $stock['warehouse_id'],
									'warehouse' => $stock['Warehouse']['name'],
									'stock' => $stock['total']
								)
							);
						}
					}
				}

				if(!empty($product['ProductsPricing'])) {
					foreach($product['ProductsPricing'] as $pricing) {
						if(empty($pricing['amount']))
							$pricing['amount'] = 0;

						$wResult = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}bimsc_pricings WHERE product_id = {$product_id} AND pricing_id = {$pricing['pricing_id']}");
						
						if(count($wResult)>0) {
							$wpdb->update( 
							    $wpdb->prefix.'bimsc_pricings', 
							    array( 
							        'price' => $pricing['amount'] * $product['SellPriceCurrency']['buy_price'],
							    ), 
							    array( 'product_id' => $product_id, 'pricing_id' => $pricing['pricing_id'] ),
							    array('%f'),
							    array('%d','%d')
							);
						} else {
							$wpdb->insert(
								$wpdb->prefix.'bimsc_pricings',
								array(
									'product_id' => $product_id,
									'pricing_id' => $pricing['pricing_id'],
									'pricing' => $pricing['Pricing']['name'],
									'price' => $pricing['amount'] * $product['SellPriceCurrency']['buy_price']
								)
							);
						}
					}
				}
				if(!empty($_GET['force_stock'])) {
					$stockTotal = 100;
				}
				// var_dump($stockTotal);
				$dproduct->set_stock_quantity($stockTotal);
				$dproduct->set_manage_stock(true);
				/*if($stockTotal > 0) {
					$dproduct->set_stock_status('instock');
				}
				else {
					$dproduct->set_manage_stock(false);
					$dproduct->set_stock_status('outofstock');
				}*/

				/*if($product['Product']['sell_price'] <=0 || $stockTotal <= 0) {
					wp_update_post( array(
    					'ID' => $product_id,
    					'post_status' => 'draft',
					) );
				} else {
					wp_update_post( array(
    					'ID' => $product_id,
    					'post_status' => 'publish',
					) );
				}*/
				
				if(!empty($product['Product']['notes'])) {
					$dproduct->set_short_description(nl2br($product['Product']['notes']));
					$dproduct->set_description(nl2br($product['Product']['notes']));
				}

				$dproduct->save();

				/*$product['Product']['image'] = str_replace("tims2_", "ss1_", $product['Product']['image']);
				$image_url = wc_rest_upload_image_from_url($product['Product']['image']);

				if(
					!empty($image_url->error)
					||	
					!empty($image_url->errors)
					||
					is_a($image_url, 'WC_Error')
					||
					$image_url instanceof WC_Error
				) {
					$image_url = null;
				}


				if(!empty($image_url)) {
					$image_id = wc_rest_set_uploaded_image_as_attachment($image_url, $dproduct->get_id());
					$dproduct->set_image_id($image_id);					
				}

				if(!empty($product['ProductsImage'])) {
					$images = [];
					foreach($product['ProductsImage'] as $imz) {
						$imz = str_replace("tims2_", "ss1_", $imz);
						$image_url = wc_rest_upload_image_from_url($imz);
						if(!empty($image_url->error))
							continue;
						if(!empty($image_url->errors))
							continue;
						if (is_a($image_url, 'WC_Error'))
							continue;
						if ($image_url instanceof WC_Error)
							continue;
						$image_id = wc_rest_set_uploaded_image_as_attachment($image_url, $dproduct->get_id());
						$images[] = $image_id;
					}

					if(!empty($images)) {
						$dproduct->set_gallery_image_ids($images);
					}
				}

				$dproduct->save();*/

				$params = array(
					'sid' => $this->sid,
					'data' => array(
						'Product' => array(
							'id' => $product['Product']['id'],
							'woocommerce_id' => $product_id,
							'modified' => $product['Product']['modified']
						)
					)
				);

				// $this->request('products/edit', $params);

				$returnData[] = array(
					'WooCommerce' => array(
						'id' => $product_id,
						'sku' => $dproduct->get_sku(),
						'name' => $dproduct->get_name(),
						'price' => $dproduct->get_price(),
						'regular_price' => $dproduct->get_regular_price(),
						'stock' => $dproduct->get_stock_quantity(),
						'date' => current_time("Y-m-d H:i:s")
					),
					'Bims' => array(
						'id' => $product['Product']['id'],
						'code' => $product['Product']['code'],
						'name' => $product['Product']['name'],
						'price' => $product['Product']['sell_price'],
						'regular_price' => $product['Product']['sell_price'],
						'stock' => $product['Product']['availability'],
						'date' => current_time("Y-m-d H:i:s")
					)
				);
			}

			return $returnData;
		}

		private function get_attribute_id_by_name($attribute_name) {
		    $attribute_id = false;
		    $attribute_taxonomies = wc_get_attribute_taxonomies();

		    foreach ($attribute_taxonomies as $attribute) {
		        if ($attribute->attribute_label === $attribute_name || $attribute->attribute_name === $attribute_name) {
		            $attribute_id = $attribute->attribute_id;
		            break;
		        }
		    }

		    return $attribute_id;
		}

		public function testtest() {
			global $wpdb;

			$global_attributes = wc_get_attribute_taxonomies();

			$args = array(
			    'post_type'      => ['product'],
			    'post_status' => 'publish',
			    'posts_per_page' => -1,
			    /*'meta_query'     => array(
			        array(
			            'key'     => '_bims_id',
			            'compare' => 'NOT NULL'
			        )
			    )*/
			);

			// sort by created
			$args['orderby'] = 'date';

			$query = new WP_Query( $args );

			if ( $query->have_posts() ) {
			    while ( $query->have_posts() ) {
			        $query->the_post();
			        $product = wc_get_product( get_the_ID() );
			        WP_CLI::line($product->get_name());
			        $attrs = [];
			 		foreach($product->get_attributes() as $key => $attribute) {
			 			if(strpos($key, "pa_") !== false)
			 				continue;

			 			$attrs[$key] = $attribute;
			 		}
			 		
			 		if(empty($attrs)) {
			 			WP_CLI::line("No attributes for {$product->get_name()}");
			 			continue;
			 		}

			 		$pattrs = [];
			 		// echo '<pre>'.print_r($attrs, true).'</pre>';
			 		$pos = 0;

			 		foreach($attrs as $attr) {
			 			$taxonomy = wc_attribute_taxonomy_name($attr->get_name());
			 			WP_CLI::line($taxonomy);
						$attribute = new WC_Product_Attribute();
						$id = $this->get_attribute_id_by_name(str_replace('pa_',null,$taxonomy));
						$attribute->set_id($id);
						$attribute->set_name($taxonomy);
			            $attribute->set_options($attr->get_options());
			            $attribute->set_position($pos++);
			            $attribute->set_visible(true);
			            $attribute->set_variation(true);

			            $pattrs[] = $attribute;
			 		}
			 		
			 		$vattrs = [];
		            foreach ($product->get_children() as $child_id) {
		                $sub_product = wc_get_product($child_id);
		                $sub_product_attributes = [];

		                foreach ($sub_product->get_attributes() as $attr_key => $attr_value) {
		                    $taxonomy = wc_attribute_taxonomy_name($attr_key);
		                    $sub_product_attributes[$taxonomy] = sanitize_title($attr_value);
		                }

		                $sub_product->set_attributes($sub_product_attributes);
		                $sub_product->save();
		            }
					$product->set_attributes($pattrs);
			 		$product->save();

			 		WP_CLI::line("Attributes for {$product->get_name()} ({$product->get_id()}) updated");
			    }
			}
			
			WP_CLI::line("All products updated");
		}

		public function getBySKU() {
			$this->login();
			// $product_id = wc_get_product_id_by_sku( $_GET['sku'] );
			$dproduct = wc_get_product( $_GET['sku'] );
			// $this->debug($dproduct); die();
			// $code = $dproduct->get_sku();
			$code = get_post_meta($_GET['sku'], '_bims_id', true);
			$result = $this->request("products/index?id={$code}", array('sid'=>$this->sid));
			$result = json_decode($result['content'], true);
			// $this->debug($result); die();
			$output = $this->updateProducts($result['data']);
			$this->debug($result['data']);
			$this->debug($output); die();
			/*$result = json_decode($result['content'], true);
			$product = $result['data'][0]['Product'];
			$this->debug($product);
			$dproduct->set_regular_price($product['sell_price']);
			$dproduct->set_price($product['sell_price']);
			$dproduct->set_manage_stock(true);
			$dproduct->set_stock_quantity(round($product['availability']));
			$dproduct->update_meta_data('_bims_sync', current_time("Y-m-d H:i:s"));		
			$d = $dproduct->save();
			if(!empty($dproduct->get_parent_id())) {
				$pproduct = wc_get_product(  $dproduct->get_parent_id() );
				$pproduct->update_meta_data('_bims_sync', current_time("Y-m-d H:i:s"));
				$pproduct->set_date_modified( time() );
				$pproduct->save();
			}
			// var_dump($dproduct);
			die();*/
		}

		public static function createTables() {
       		global $wpdb;

       		$charset_collate = $wpdb->get_charset_collate();
       		$tabla_stocks = $wpdb->prefix.'bimsc_stocks';
       		$tabla_pricings = $wpdb->prefix.'bimsc_pricings';
       		$tabla_products = $wpdb->prefix.'bimsc_products';
       		$tabla_posales = $wpdb->prefix.'bimsc_posales';

			$sql = "CREATE TABLE IF NOT EXISTS `{$tabla_stocks}` (
			  `id` int(11) NOT NULL AUTO_INCREMENT,
			  `product_id` bigint(11) NOT NULL,
			  `warehouse_id` bigint(11) NOT NULL,
			  `warehouse` varchar(255) NOT NULL,
			  `stock` decimal(18,2) DEFAULT 0,
			  PRIMARY KEY (`id`),
			  KEY `product_id` (`product_id`),
			  KEY `warehouse` (`warehouse`)
			) {$charset_collate};";
			$wpdb->get_results($sql);

			$sql = "CREATE TABLE IF NOT EXISTS `{$tabla_pricings}` (
			  `id` int(11) NOT NULL AUTO_INCREMENT,
			  `product_id` bigint(11) NOT NULL,
			  `pricing_id` bigint(11) NOT NULL,
			  `pricing` varchar(255) NOT NULL,
			  `price` decimal(18,2) DEFAULT 0,
			  PRIMARY KEY (`id`),
			  KEY `product_id` (`product_id`),
			  KEY `pricing_id` (`pricing_id`)
			) {$charset_collate};";
			$wpdb->get_results($sql);

			$sql = "CREATE TABLE IF NOT EXISTS `{$tabla_products}` (
			  `id` int(11) NOT NULL AUTO_INCREMENT,
			  `product_id` bigint(11) NOT NULL,
			  `code` VARCHAR(255),
			  `text` longtext NULL,
			  PRIMARY KEY (`id`),
			  KEY `product_id` (`product_id`)
			) {$charset_collate};";
			$wpdb->get_results($sql);

			$sql = "CREATE TABLE IF NOT EXISTS `{$tabla_posales}` (
			  `id` int(11) NOT NULL AUTO_INCREMENT,
			  `company_id` bigint(11) NOT NULL,
			  `agency_id` bigint(11) NOT NULL,
			  `posale_id` bigint(11) NOT NULL,
			  `company_name` varchar(255) NOT NULL,
			  `agency_name` varchar(255) NOT NULL,
			  `posale_name` varchar(255) NOT NULL,
			  `address` varchar(255) NOT NULL,
			  `address2` varchar(255) NOT NULL,
			  `city` varchar(255) NOT NULL,
			  PRIMARY KEY (`id`),
			  KEY `company_id` (`company_id`),
			  KEY `posale_id` (`posale_id`),
			  KEY `agency_id` (`agency_id`)
			) {$charset_collate};";
			$wpdb->get_results($sql);

		}

		function custom_price( $price, $product ) {
			global $wpdb;
			$user = wp_get_current_user();
			$role = $user->roles[0];

			if(!$user->ID)
				return $price;

			$pricings = unserialize(get_option('bimsc_roles'));

			$pricing_id = $pricings[$role];
			if(empty($pricing_id))
				return $price;

		    $pricing = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}bimsc_pricings WHERE product_id = {$product->get_id()} AND pricing_id = {$pricing_id} AND price > 0");

		    if(empty($pricing))
		    	return $price;

		    $price = $pricing[0]->price;
			return $price;
		}

		function filter_woocommerce_stock_html( $html, $product ) {
			return $html;
			global $wpdb;
		    $stocks = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}bimsc_stocks WHERE product_id = {$product->get_id()}");
		    
		    $warehousesData = get_option('bimsc_warehouses');
		    $warehouses = array();
		    if(!empty($warehousesData)) {
		    	$warehouses = explode(",", $warehousesData);
		    }
			
		    if(empty($stocks) || empty($warehouses))
		    	return $html;
			
			$newHtml = '<table class="table table-bordered">';
			$newHtml .= '<thead><tr><th>Almacén</th><th>Disponibilidad</th></tr></thead>';
			$newHtml .= '<tbody>';
			
			// $this->debug($stocks);
			
			foreach($stocks as $stock) {
				if(in_array($stock->warehouse_id, $warehouses)) {
					$available = $stock->stock > 0 ? 'SÍ' : 'NO'; 
					$newHtml .= '<tr>';
					$newHtml .= "<td>{$stock->warehouse}</td><td>{$available}</td>";
					$newHtml .= '</tr>';
				}
			}
			// var_dump($product);
			$newHtml .= "<tr><td colspan=\"2\">Actualizado el: {$product->get_date_modified()->date("d/m/Y H:i:s")}</td></tr>";

			$newHtml .= '</tbody></table>';
			return $newHtml;
		}
	}

	function wcbims_add_meta_box() {
	    add_meta_box(
	        'wcbims_meta_box', // ID del meta box
	        'Enviar a BIMS', // Título
	        'wcbims_meta_box_callback', // Callback function
	        'shop_order', // Pantalla donde se mostrará
	        'side', // Contexto
	        'default' // Prioridad
	    );
	}

	function wcbims_meta_box_callback($post) {
	    global $wpdb;

	    $posales = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}bimsc_posales ORDER BY posale_name ASC");
	    
	    $selected_branch = get_post_meta($post->ID, '_bims_branch', true);
	    $bims_id = get_post_meta($post->ID, '_bims_id', true);
	    $bims_id2 = get_post_meta($post->ID, 'bims_id', true);

	    if (!empty($bims_id) || !empty($bims_id2)) {
	        echo '<p><strong>Este pedido ya ha sido enviado a BIMS.</strong></p>';
	    } else {
		    echo '<label for="wcbims_branch">Seleccionar Punto de Venta: </label>';
		    echo '<select id="wcbims_branch" name="wcbims_branch" style="width: 100%;">';
		    echo '<option value="">- Seleccione -</option>';
		    foreach($posales as $posale) {
		        echo '<option value="' . esc_attr($posale->posale_id) . '" ' . selected($selected_branch, $posale->posale_id, false) . '>' . esc_html("{$posale->posale_name} ($posale->agency_name)") . '</option>';
		    }
		    
		    echo '</select>';
		    echo '<button id="wcbims_send_to_bims" class="button" style="margin-top: 10px;"' . ($bims_id ? ' disabled' : '') . '>Enviar a BIMS</button>';
	    	wp_nonce_field('wcbims_nonce_action', 'wcbims_nonce');
	    }
	}

	// Hook para cargar los scripts necesarios
	function wcbims_admin_scripts($hook) {
	    if ($hook === 'post.php' && get_post_type() === 'shop_order') {
	        wp_enqueue_script('wcbims_script', plugin_dir_url(__FILE__) . 'js/wcbims-script.js', array('jquery'), null, true);
	        wp_localize_script('wcbims_script', 'wcbims_ajax', array(
	            'ajax_url' => home_url('wc-api/bimsc_send_order'),//admin_url('admin-ajax.php'),
	            'nonce' => wp_create_nonce('wcbims_nonce_action')
	        ));
	    }
	}

	function wcbims_display_bims_id($order) {
	    $bims_id = get_post_meta($order->get_id(), '_bims_id', true);
	    if ($bims_id) {
	        echo '<p><strong>ID de BIMS:</strong> ' . esc_html($bims_id) . '</p>';
	    }
	}

	add_action('add_meta_boxes', 'wcbims_add_meta_box');
	add_action('admin_enqueue_scripts', 'wcbims_admin_scripts');
	add_action('woocommerce_admin_order_data_after_order_details', 'wcbims_display_bims_id');


	// Añadir campo meta _bims_id en la página de edición de producto principal
	function agregar_campo_meta_bims_id() {
	    global $post;

	    if ($post->post_type == 'product') {
	        $valor_bims_id = get_post_meta($post->ID, '_bims_id', true);
	        ?>
	        <div class="options_group">
	            <p class="form-field">
	                <label for="_bims_id"><?php esc_html_e('BIMS ID', 'woocommerce'); ?></label>
	                <input type="text" id="_bims_id" name="_bims_id" value="<?php echo esc_attr($valor_bims_id); ?>" />
	            </p>
	        </div>
	        <?php
	    }
	}
	add_action('woocommerce_product_options_general_product_data', 'agregar_campo_meta_bims_id');

	// Guardar el campo meta _bims_id
	function guardar_campo_meta_bims_id($post_id) {
	    if (isset($_POST['_bims_id'])) {
	        update_post_meta($post_id, '_bims_id', sanitize_text_field($_POST['_bims_id']));
	    }
	}
	add_action('woocommerce_process_product_meta', 'guardar_campo_meta_bims_id');

	// Añadir campo meta _bims_id en cada variación de producto
	function agregar_campo_meta_bims_id_variacion($loop, $variation_data, $variation) {
	    $valor_bims_id = get_post_meta($variation->ID, '_bims_id', true);
	    ?>
	    <div class="form-row form-row-full">
	        <label for="_bims_id_<?php echo $variation->ID; ?>"><?php esc_html_e('BIMS ID (Variación)', 'woocommerce'); ?></label>
	        <input type="text" class="short" id="_bims_id_<?php echo $variation->ID; ?>" name="variation_bims_id[<?php echo $variation->ID; ?>]" value="<?php echo esc_attr($valor_bims_id); ?>" />
	    </div>
	    <?php
	}
	add_action('woocommerce_variation_options_pricing', 'agregar_campo_meta_bims_id_variacion', 10, 3);

	// Guardar el campo meta _bims_id para cada variación
	function guardar_campo_meta_bims_id_variacion($variation_id) {
	    if (isset($_POST['variation_bims_id'][$variation_id])) {
	        update_post_meta($variation_id, '_bims_id', sanitize_text_field($_POST['variation_bims_id'][$variation_id]));
	    }
	}
	add_action('woocommerce_save_product_variation', 'guardar_campo_meta_bims_id_variacion', 10, 2);


	
	add_action( 'plugins_loaded', array( 'BimsC', 'init' ));
	add_action( 'plugins_loaded', array( 'BimsC', 'createTables' ));
	add_filter( 'is_protected_meta', '__return_false' );	
}
?>