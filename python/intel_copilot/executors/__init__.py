"""Backend-specific plan executors.

Every executor satisfies the ``Executor`` protocol in ``base.py``. The
router picks one per plan; executors never call each other.
"""
