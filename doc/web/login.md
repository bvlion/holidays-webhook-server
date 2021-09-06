# web から Google Login を行う

## get /auth/redirect

Google の Login 画面へリダイレクトさせる

### request

内容 | 値 | 説明
:--|:--|:--

```
http://localhost:8000/auth/redirect
```

### response

Google のアカウント選択へリダイレクト URL（302）

内容 | 値 | 説明
:--|:--|:--

```
https://accounts.google.com/o/oauth2/auth/oauthchooseaccount
```

## get /login/callback

Google Auth リダイレクトからの情報を取得・登録する

### request

内容 | 値 | 説明
:--|:--|:--

```
http://localhost:8000/login/callback
```

### response

登録・更新を行なったユーザー情報

内容 | 値 | 説明
:--|:--|:--
api_token | CuTtoRdf2lZZfkAUcp2hYrj0MjPqYxxxxxxxxxx | 認証トークン（60 桁乱数）
user_name | bvlion | Google に登録しているユーザー名
owner_flag | true | グループのオーナーか（Google ログインの場合は常に true）

```
{
  "user":{
    "api_token":"CuTtoRdf2lZZfkAUcp2hYrj0MjPqYxxxxxxxxxx",
    "user_name":"bvlion",
    "owner_flag":true
  }
}
```