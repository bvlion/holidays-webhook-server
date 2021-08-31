CREATE TABLE exec_results
(
    id INT AUTO_INCREMENT NOT NULL COMMENT 'ID',
    command_id INT NOT NULL COMMENT 'コマンド ID',
    exec_time DATETIME NOT NULL COMMENT '実行時間',
    response TEXT NOT NULL COMMENT '実行レスポンス',
    created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '作成日時',
    updated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新日時',
    deleted DATETIME COMMENT '削除日時',
    PRIMARY KEY (id)
) COMMENT '実行結果情報';