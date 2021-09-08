CREATE TABLE commands
(
    id INT AUTO_INCREMENT NOT NULL COMMENT 'ID',
    target_name VARCHAR(256) NOT NULL COMMENT '名称',
    target_id INT NOT NULL COMMENT 'グループ or ユーザー ID',
    target_type ENUM('group', 'user') NOT NULL COMMENT 'グループ or ユーザー',
    url VARCHAR(1024) NOT NULL COMMENT 'URL',
    method VARCHAR(32) NOT NULL COMMENT 'リクエストメソッド',
    body_type ENUM('json', 'form_params', 'query') NOT NULL COMMENT '送信タイプ',
    headers VARCHAR(4096) NOT NULL COMMENT 'ヘッダ情報（json 形式）',
    parameters VARCHAR(4096) NOT NULL COMMENT 'パラメータ情報（json 形式）',
    created_at DATETIME NOT NULL COMMENT '作成日時',
    updated_at DATETIME NOT NULL COMMENT '更新日時',
    deleted_at DATETIME COMMENT '削除日時',
    PRIMARY KEY (id)
) COMMENT 'コマンド情報';
