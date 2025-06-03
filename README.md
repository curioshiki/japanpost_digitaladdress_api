# japanpost_digitaladdress_api
日本郵便「郵便番号・デジタルアドレスAPI」の実装サンプル

郵便番号・デジタルアドレスともに検索できる簡易なサンプルです。
クライアントID、シークレットキーは、このサンプルでは環境変数に設定するようにしています。詳しくは Scripts/api_handler.php の冒頭部分をごらんください。

APIのエンドポイントは、郵便番号・デジタルアドレスAPIの登録をすませると、APIリファレンスの中から見ることができます。
テスト用エンドポイントはもしかしたらユーザごとに異なるかもしれません。登録後にご自身で確認ください。
Scripts/api_handler.php内
本番用
define('PROD_API_BASE_URL', 'https://api.da.pf.japanpost.jp');
テスト用
define('TEST_API_BASE_URL', 'https://stub-qz73x.da.pf.japanpost.jp');
