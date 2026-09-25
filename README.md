# ryotakashiwagigoto.github.io

後藤良太（Ryota Goto）の研究者ページの公開用リポジトリです。

- `site/` … WordPress（Local）から Simply Static で書き出した HTML 一式
- `scripts/refresh-researchmap.php` … Publications / CV の業績を researchmap から取り直して差し替えるスクリプト
- `scripts/researchmap.php` … テーマの `inc/researchmap.php` のコピー（表示を変えたら、こちらにもコピーする）
- `.github/workflows/deploy.yml` … push 時と毎週月曜 6:17（日本時間）に自動で公開

## 更新のしかた

1. Local の WordPress で編集する
2. 管理画面の「Simply Static」→「Generate」で `site/` に書き出す
3. このフォルダで次を実行する

```
git add -A
git commit -m "Update site"
git push
```
