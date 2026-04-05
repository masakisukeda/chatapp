# AGENTS.md (Chat.app)

このフォルダを触る AI / 自動化ツール向けの「最初に読む」メモです。

## 0. 共通ルール（最優先）
- 共通指示書（最新版URL）: `https://drsp.cc/app/AGENTS.md`
- ローカル編集元: `/Users/masakisukeda/Library/CloudStorage/GoogleDrive-masaki.sukeda@gmail.com/マイドライブ/Playground/AGENTS.md`
- スコープは `/app` `/chat` `/dic` `/mng` のみ。無関係フォルダの編集・デプロイは禁止。
- 通常デプロイは `main` へ push -> GitHub Actions で実施（直接FTPアップロードは禁止）。
- `/chat` の公開反映先は `/public_html/drsp.cc/chat`（`/virtual/sukeda/public_html/chat` と `/virtual/sukeda/public_html/drsp.cc/chat` は非公開側の旧/別パス）。

## 1. 対象
- 公開URL: `https://drsp.cc/chat/`
- 主要実装:
  - `index.html`（フロント本体）
  - `manual.html`（運用マニュアル）
  - `api.php`（投稿/投票などAPI）
  - `data/chatapp.sqlite`（データ）

## 2. ローカル起動
```bash
cd /Users/masakisukeda/Library/CloudStorage/GoogleDrive-masaki.sukeda@gmail.com/マイドライブ/DiSA/プロジェクト/チャットアプリ
./start-local.sh
# http://127.0.0.1:8000
```

## 3. 作業ルール
- 変更は最小差分。
- UI/コンポーネント修正時は `DESIGN_chat.md` を必ず参照し、同ガイドのルールで一貫性を保つ。
- 既存UIのトーン・文言・導線を維持する。
- モーダルやフォーム挙動は、既存仕様を崩さない（勝手に閉じる条件を増やさない）。
- `api.php` は副作用が大きいので、修正時は入力/保存/読み出し経路を必ず確認する。
- `data/admin_key.txt` など認証系ファイルを不用意に編集しない。
- `lp/index.html` と `manual.html` の「最近のアップデート」は最大 `3` 件までにする（新規追加時は先頭追加＋最古を削除）。

## 4. 反映前チェック
- 画面確認: `index.html` / `manual.html`
- LP修正時: `./lp/check-lp-regression.sh`
- API変更時: 主要操作（投稿・編集・削除・投票）を最低1周確認
- PHP構文確認例:
```bash
php -l api.php
```

## 4.1 デプロイクイックチェック（時短）
- デプロイ前に公開反映先を確認:
  - 正: `/public_html/drsp.cc/chat`
  - 旧/非公開側: `/virtual/sukeda/public_html/chat` `/virtual/sukeda/public_html/drsp.cc/chat`
- デプロイ後は以下を実行して、HTTPとAPI契約をまとめて確認:
```bash
bash scripts/check-deploy-chat.sh
```
- `getPoll` の戻り値は `pollList` を含むこと（導線修正の回帰チェック）。
- GitHub API監視で `403` が出る場合は未認証レート制限を疑う（`gh` または認証トークン利用）。

## 4.2 デプロイ確認（必須）
- `push` 後、必ず GitHub Actions のステータスを確認すること。
- デプロイ確認コマンド:
```bash
# Actions完了を確認（例）
gh run list --branch main --limit 5
gh run watch <RUN_ID> --exit-status

# 公開反映確認
curl -I https://drsp.cc/chat/app.js
# -> HTTP 200 かつレスポンスヘッダ（Last-Modified など）に更新が反映されていること
```
- 上記確認前に「デプロイ完了」と報告しないこと。

## 4.3 ロールバック手順
- デプロイ後に不具合が発生した場合:
```bash
# 直前のコミットを打ち消す
git revert HEAD --no-edit
git push origin main
# -> GitHub Actions が自動で旧バージョンをデプロイ

# 複数コミット戻す場合
git revert HEAD~2..HEAD --no-edit
git push origin main
```
- `revert` 後も必ず `curl -I` で HTTP `200` を確認すること。

## 4.4 デプロイ完遂ルール
- デプロイに関してユーザーへの確認は不要。以下を自己完結で実施し、結果のみ報告すること。
1. `push` -> GitHub Actions 完了を待つ
2. `curl -I https://drsp.cc/chat/app.js` で HTTP `200` を確認
3. 不具合があれば即座にロールバック（ユーザーへの報告前に実施）
```bash
git revert HEAD --no-edit
git push origin main
```

## 5. NG
- 無関係ファイルの一括整形。
- DBファイルの直接破壊。
- 本番反映手順が未確認のまま「反映済み」と報告。
