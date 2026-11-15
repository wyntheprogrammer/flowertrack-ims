"""Reuse predict.py for many batches in one Python process."""
import json
import sys
from predict import predict


def predict_many(items):
    if not isinstance(items, list) or len(items) > 1000:
        raise ValueError('Expected at most 1000 prepared batches.')
    results = {}
    for item in items:
        key = str(item['key'])
        if key in results:
            raise ValueError('Duplicate batch key.')
        try:
            results[key] = predict(item['payload'])
        except Exception as error:
            results[key] = {'status': 'Error', 'message': str(error)}
    return {'status': 'ok', 'results': results}


def self_test():
    payload = {
        'as_of_date': '2026-11-15', 'opening_quantity': 100, 'history': [],
        'fallback': {'spoil_rates': [0.1] * 7, 'release_rates': [0.2] * 7,
                     'method': 'Flower/storage/age fallback'},
    }
    result = predict_many([
        {'key': '1', 'payload': payload},
        {'key': '2', 'payload': {**payload, 'fallback': None}},
        {'key': '3', 'payload': {**payload, 'opening_quantity': -1}},
    ])['results']
    assert result['1'] == predict(payload)
    assert result['2']['status'] == 'InsufficientData'
    assert result['3']['status'] == 'Error'
    assert predict_many([])['results'] == {}
    try:
        predict_many([{'key': '1', 'payload': payload}] * 2)
    except ValueError:
        pass
    else:
        raise AssertionError('Duplicate key accepted.')
    return {'status': 'ok', 'message': 'Batch forecast self-test passed.'}


if __name__ == '__main__':
    try:
        output = self_test() if '--self-test' in sys.argv else predict_many(json.load(sys.stdin)['items'])
        print(json.dumps(output, allow_nan=False))
    except Exception as error:
        print(json.dumps({'status': 'Error', 'message': str(error)}))
        sys.exit(1)
