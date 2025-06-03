document.addEventListener('DOMContentLoaded', function() {
    const searchCodeInput = document.getElementById('search-code-display');
    const postalCodeHidden = document.querySelector('input[name="postal-code"]');
    const prefInput = document.getElementById('address-level1');
    const cityInput = document.getElementById('address-level2');
    const streetInput = document.getElementById('address-line1');
    const buildingInput = document.getElementById('address-line2');
    const messageDiv = document.getElementById('address-api-message');

    const digitalAddressWithHyphenRegex = /^[A-Z0-9]{3}-[A-Z0-9]{4}$/i;
    const digitalAddressWithoutHyphenRegex = /^[A-Z0-9]{7}$/i;
    const postalCode7DigitRegex = /^\d{7}$/;

    // --- 不正利用対策用の変数 ---
    const MAX_REQUESTS = 5; // 短時間内の最大リクエスト数
    const TIME_WINDOW_MS = 10000; // 監視する時間窓 (ミリ秒) = 10秒
    const BLOCK_DURATION_MS = 600000; // ブロックする期間 (ミリ秒) = 10分
    let requestTimestamps = []; // APIリクエストのタイムスタンプを記録する配列
    let isBlocked = false; // ブロック状態フラグ
    let blockTimeoutId = null; // ブロック解除タイマーのID
    // --- 不正利用対策用の変数ここまで ---

    function canMakeRequest() {
        if (isBlocked) {
            messageDiv.textContent = `短時間に多くのリクエストがあったため、検索を制限しています。住所の手入力は可能ですので、必要に応じて手動で入力してください。`;
            return false;
        }

        const now = Date.now();
        // TIME_WINDOW_MS より古いタイムスタンプをフィルタリングして削除
        requestTimestamps = requestTimestamps.filter(timestamp => now - timestamp < TIME_WINDOW_MS);

        if (requestTimestamps.length >= MAX_REQUESTS) {
            isBlocked = true;
            messageDiv.textContent = `短時間に多くのリクエストがあったため、検索を制限しています。住所の手入力は可能ですので、必要に応じて手動で入力してください。`;
            console.warn(`API request limit reached. Blocking for ${BLOCK_DURATION_MS / 1000} seconds.`);

            // ブロック解除タイマーを設定
            if (blockTimeoutId) clearTimeout(blockTimeoutId); // 既存のタイマーがあればクリア
            blockTimeoutId = setTimeout(() => {
                isBlocked = false;
                requestTimestamps = []; // ブロック解除時にタイムスタンプもリセット
                messageDiv.textContent = '検索制限が解除されました。再度お試しいただけます。';
                console.log('API request block lifted.');
            }, BLOCK_DURATION_MS);
            return false;
        }
        return true;
    }

    function recordRequest() {
        requestTimestamps.push(Date.now());
    }


    if (searchCodeInput) {
        searchCodeInput.addEventListener('input', function() {
            const inputValue = this.value.trim().toUpperCase();

            let shouldFetch = false;
            let searchCodeForApi = '';

            if (inputValue.length === 8) {
                if (digitalAddressWithHyphenRegex.test(inputValue)) {
                    shouldFetch = true;
                    searchCodeForApi = inputValue.replace(/-/g, '');
                } else {
                    const postalCodeWithoutHyphen = inputValue.replace(/-/g, '');
                    if (postalCodeWithoutHyphen.length === 7 && postalCode7DigitRegex.test(postalCodeWithoutHyphen)) {
                        shouldFetch = true;
                        searchCodeForApi = postalCodeWithoutHyphen;
                    }
                }
            } else if (inputValue.length === 7) {
                if (postalCode7DigitRegex.test(inputValue)) {
                    shouldFetch = true;
                    searchCodeForApi = inputValue;
                } else if (digitalAddressWithoutHyphenRegex.test(inputValue)) {
                    shouldFetch = true;
                    searchCodeForApi = inputValue;
                }
            }

            if (shouldFetch && searchCodeForApi) {
                if (!canMakeRequest()) { // ★ リクエスト可能かチェック
                    return; // ブロック中なら何もしない
                }
                recordRequest(); // ★ リクエストを記録

                // console.log('Proceeding with fetch API call...');
                messageDiv.textContent = '検索中...'; // 検索開始をユーザーに通知
                const formData = new FormData();
                formData.append('search_code', searchCodeForApi);

                fetch('Scripts/api_handler.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    const contentType = response.headers.get("content-type");
                    if (!contentType || !contentType.includes("application/json")) {
                        return response.text().then(text => {
                            console.error('Server response was not JSON. Raw response:', text);
                            throw new Error(`サーバーからの応答がJSON形式ではありませんでした。応答: ${text.substring(0,200)}`);
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    prefInput.value = ''; cityInput.value = ''; streetInput.value = ''; buildingInput.value = ''; postalCodeHidden.value = '';
                    if (data.error) {
                        messageDiv.textContent = `エラー: ${data.message || '住所の取得に失敗しました。'}`;
                        if (data.api_error_code && data.api_error_code !== 'N/A') {
                             messageDiv.textContent += ` (コード: ${data.api_error_code})`;
                        }
                        console.error('API Handler Error:', data);
                    } else if (data.addresses && data.addresses.length > 0) {
                        const addr = data.addresses[0];
                        postalCodeHidden.value = addr.zip_code || '';
                        prefInput.value = addr.pref_name || '';
                        cityInput.value = addr.city_name || '';
                        let streetAddress = addr.town_name || '';
                        if (addr.block_name) {
                            streetAddress += (streetAddress ? ' ' : '') + addr.block_name;
                        }
                        streetInput.value = streetAddress;
                        if (data.searchtype === 'bizzipcode' && addr.biz_name) {
                            buildingInput.value = addr.biz_name;
                        } else if (addr.other_name) {
                            buildingInput.value = addr.other_name;
                        } else {
                            buildingInput.value = '';
                        }
                        messageDiv.textContent = '住所が自動入力されました。';
                    } else {
                        messageDiv.textContent = '該当する住所情報が見つかりませんでした。';
                    }
                })
                .catch(error => {
                    prefInput.value = ''; cityInput.value = ''; streetInput.value = ''; buildingInput.value = ''; postalCodeHidden.value = '';
                    messageDiv.textContent = `検索リクエストエラー: ${error.message}`;
                    console.error('Fetch Catch Error:', error);
                });
            } else if (inputValue.length === 0) { // 入力がクリアされたらメッセージもクリア
                messageDiv.textContent = '';
            }
        });
    }

    const form = document.getElementById('fm_license');
    if (form) {
        form.addEventListener('reset', function() {
            if (postalCodeHidden) postalCodeHidden.value = '';
            if (messageDiv) messageDiv.textContent = '';
            if (prefInput) prefInput.value = '';
            if (cityInput) cityInput.value = '';
            requestTimestamps = [];
            isBlocked = false;
            if (blockTimeoutId) clearTimeout(blockTimeoutId);
            blockTimeoutId = null;
            console.log('Form reset: API request limits and block state cleared.');
        });
    }
});
