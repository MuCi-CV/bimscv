<?php
class BimsC_Shopify
{
    var $url;
    var $base_url;
    var $version;
    var $token;


    public function __construct() {
        $this->url = getenv('SHOPIFY_URL') ?: "https://albertina-py.myshopify.com";
        $this->base_url = getenv('SHOPIFY_URL') ?: "https://albertina-py.myshopify.com";
        $this->version = getenv('SHOPIFY_API_VERSION') ?: '2024-01';
        $this->token = getenv('SHOPIFY_TOKEN') ?: '';
        
        if (empty($this->token)) {
            error_log('SHOPIFY_TOKEN no está configurado en .env');
        }
    }

    public function listProductsByCollection($collection_id) {
        $this->url = "{$this->base_url}/admin/api/{$this->version}/";
        $products = [];

        do {
            if(!empty($result['paginate']['next'])) {
                $result = $this->request($result['paginate']['next']);
            } else {
                $result = $this->request($this->url."collections/{$collection_id}/products.json?status=draft&limit=50");
            }

            if(!empty($result)) {
                foreach($result['response']->products as $product) {
                    //if($product->status != 'draft')
                        $products[] = $product;
                }
            }
        } while(!empty($result['paginate']['next']));

        return $products;
    }

    public function getProduct($product_id) {
        $this->url = "{$this->base_url}/admin/api/{$this->version}/";
        $result = $this->request($this->url."products/{$product_id}.json");
        return $result['response']->product;
    }

    public function getVariant($variant_id) {
        $this->url = "{$this->base_url}/admin/api/{$this->version}/";
        $result = $this->request($this->url."variants/{$variant_id}.json");
        return $result['response']->variant;
    }

    public function getCustomer($customer_id) {
        $this->url = "{$this->base_url}/admin/api/{$this->version}/";
        $result = $this->request($this->url."customers/{$customer_id}.json");
        return $result['response']->customer;
    }

    public function getDraftOrder($draft_order_id) {
        $this->url = "{$this->base_url}/admin/api/{$this->version}/";
        $result = $this->request($this->url."draft_orders/{$draft_order_id}.json");
        return $result['response']->draft_order;
    }

    public function listProducts($options = []) {
        $this->url = "{$this->base_url}/admin/api/{$this->version}/";
        $products = [];

        do {
            if(!empty($result['paginate']['next'])) {
                $result = $this->request($result['paginate']['next']);
            } else {
                $result = $this->request($this->url.'products.json?limit=50');
            }
            if(!empty($result)) {
                foreach($result['response']->products as $product) {
                    if($product->status != 'draft')
                        $products[] = $product;
                }
            }
        } while(!empty($result['paginate']['next']));

        return $products;
    }

    public function listOrders($ids = array()) {;
        $this->url = "{$this->base_url}/admin/api/{$this->version}/";

        if(!empty($ids)) {
            $chunkedIds = array_chunk($ids, 50);

            $results = [];

            foreach ($chunkedIds as $chunk) {
                $result = $this->request($this->url.'orders.json?ids='.implode(',', $chunk));
                foreach($result['response']->orders as $order)
                    $results[] = $order;
            }

            $result = [
                'response'  => (object) [
                    'orders'    => $results
                ]
            ];

            return $result;
        } else {
            $result = $this->request($this->url.'orders.json?status=success&limit=50');
        }


        return $result;
    }

    public function listDraftOrders($ids = array()) {;
        $this->url = "{$this->base_url}/admin/api/{$this->version}/";

        if(!empty($ids)) {
            $chunkedIds = array_chunk($ids, 50);

            $results = [];

            foreach ($chunkedIds as $chunk) {
                $result = $this->request($this->url.'draft_orders.json?ids='.implode(',', $chunk));
                foreach($result['response']->draft_orders as $order)
                    $results[] = $order;
            }

            $result = [
                'response'  => (object) [
                    'orders'    => $results
                ]
            ];

            return $result;
        } else {
            $result = $this->request($this->url.'draft_orders.json?limit=50');
        }


        return $result;
    }

    public function request($endpoint, $data = array()) {
        $header = array();
        $header[] = 'Accept: application/json';
        $header[] = 'Content-Type: application/json';
        $header[] = 'X-Shopify-Access-Token: '.$this->token;
        $session = curl_init();

        curl_setopt($session, CURLOPT_URL, $endpoint);
        if(!empty($data['fields']) && (empty($data['method'])||(!empty($data['method']&&$data['method']=='POST')))) {
            curl_setopt($session, CURLOPT_POST, true);
            curl_setopt($session, CURLOPT_POSTFIELDS, json_encode($data['fields']));
        }

        if(!empty($data['method']) && ($data['method'] != 'POST' && $data['method'] != 'GET')) {
            curl_setopt($session, CURLOPT_CUSTOMREQUEST, $data['method']);
            curl_setopt($session, CURLOPT_POSTFIELDS, json_encode($data['fields']));
        }

        curl_setopt($session, CURLOPT_HTTPHEADER, $header);
        curl_setopt($session, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($session, CURLOPT_HEADER, true);
        $response = curl_exec($session);
        $headerSize = curl_getinfo( $session , CURLINFO_HEADER_SIZE );
        $headerStr = substr( $response , 0 , $headerSize );
        $bodyStr = substr( $response , $headerSize );
        curl_close($session);

        $headersArray = explode("\n", $headerStr);
        $headers = [];
        foreach($headersArray as $array) {
            if(empty($array))
                continue;
            if(strpos($array, ":") === false) {
                $headers[] = $array;
            } else {
                $key = explode(":", $array)[0];
                $headers[$key] = str_replace($key.":","", $array);
            }
        }

        $response = json_decode($bodyStr);

        return [
            'paginate' => $this->paginate($headers),
            'response' => $response
        ];
    }

    private function paginate($headers) {
        $url_data = [];

        if(!empty($headers['link'])) {
            $links = $headers['link'];
            $urls = explode(',', $links);
            foreach ($urls as $url) {
                $url_parts = explode(';', $url);
                $url_parts[0] = trim($url_parts[0], '<>');
                $url_parts[0] = str_replace("<","",$url_parts[0]);
                $url_parts[1] = str_replace("rel=","",trim($url_parts[1]));
                $url_parts[1] = str_replace("\"","",$url_parts[1]);
                $url_data[$url_parts[1]] = trim($url_parts[0]);
            }
        }

        return $url_data;
    }

}
?>
