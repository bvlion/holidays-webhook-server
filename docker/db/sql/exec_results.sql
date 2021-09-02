CREATE TABLE exec_results
(
    id INT AUTO_INCREMENT NOT NULL COMMENT 'ID',
    command_id INT NOT NULL COMMENT 'コマンド ID',
    exec_time DATETIME NOT NULL COMMENT '実行時間',
    response TEXT NOT NULL COMMENT '実行レスポンス',
    created_at DATETIME NOT NULL COMMENT '作成日時',
    updated_at DATETIME NOT NULL COMMENT '更新日時',
    deleted_at DATETIME COMMENT '削除日時',
    PRIMARY KEY (id)
) COMMENT '実行結果情報';