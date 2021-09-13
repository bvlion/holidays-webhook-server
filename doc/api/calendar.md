# 登録したコマンドを実行する

## get /api/calendar/holiday

対象日を祝日に設定しているかを取得する

### request

内容 | 値 | 説明
:--|:--|:--
Authorization | 60桁乱数 | ヘッダパラメータ
type | user, group | 祝日設定した対象
date | yyyy-MM-dd | 対象日
country_code | jp | 祝日検索したい国コード（option）

```
http://localhost:8000/api/calendar/holiday
```

### response

祝日設定状況

内容 | 値 | 説明
:--|:--|:--
holiday | bool | 祝日設定されているか
force | bool | 個人のカレンダーに設定されているか

```
{
  "holiday": false,
  "force": false
}
```

## post /api/calendar/upsert

指定日を祝日に設定する 
既に設定されていた場合は上書きを行う

### request

内容 | 値 | 説明
:--|:--|:--
Authorization | 60桁乱数 | ヘッダパラメータ
type | user, group | 祝日設定する対象
date | yyyy-MM-dd | 対象日
holiday | 0, 1 | 祝日として設定する場合は 1

```
http://localhost:8000/api/calendar/upsert
```

### response

実行結果（配列）

内容 | 値 | 説明
:--|:--|:--
target_id | 対象ユーザー ID | DB 設定値
target_type | user, group | 祝日設定対象
target_date | yyyy-MM-dd | 対象日
is_holiday | 0, 1 | 祝日として設定する場合は 1
id | 祝日設定 ID | DB 設定値

```
{
  "target_id": 1,
  "target_type": "group",
  "target_date": "2021-09-14",
  "is_holiday": "0",
  "id": 6
}
```
